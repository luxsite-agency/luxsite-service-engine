<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Адреси API не вгадуються (23.10.2026: агент клієнта отримав 404 на хості сайту й вирішив, що API нічого не дає):
 * /api/admin і /mcp на домені сайту — JSON 404 wrong_host з адресою адмінки, /api/studio на хості адмінки — wrong_host з
 * доменами міст лише свого сайту; правильні хости відповідають як раніше (401 без токена); /me і MCP me дають endpoints і
 * capabilities (що можна цим токеном, що лише в адмінці); сторінка API-токенів показує справжні адреси.
 */
final class ApiHostHintsTest extends HttpKernelTestCase
{
    private string $slug;
    private string $apex;
    private string $branch;
    private string $adminHost;
    private string $otherApex;
    /** @var list<int> */
    private array $siteIds = [];
    /** @var list<string> */
    private array $emails = [];
    private string $password;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->slug = 'ci-hints-' . bin2hex(random_bytes(3));
        $this->apex = $this->slug . '.example';
        $this->branch = 'branch.' . $this->apex;
        $this->adminHost = 'admin.' . $this->apex;
        $this->otherApex = 'other-' . $this->slug . '.example';
        $_SERVER['ADMIN_HOST'] = $_ENV['ADMIN_HOST'] = $this->adminHost;
        putenv('ADMIN_HOST=' . $this->adminHost);
        self::bootKernel();

        $em = $this->em();
        $site = (new ServiceSite())->setSlug($this->slug)->setName('CI Hints Site')->setApexDomain($this->apex);
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->apex)->setSlug('main')->setIsApex(true)->setNameNomUk('Головне'));
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->branch)->setSlug('branch')->setNameNomUk('Філія'));
        $category = (new ServiceCategory())->setSite($site)->setSlugUk('centers')->setTitleUk('Центри');
        $em->persist($category);
        // другий сайт того ж встановлення: його домени не мають з'являтися в підказці хоста адмінки першого
        $other = (new ServiceSite())->setSlug('other-' . $this->slug)->setName('CI Other Site')->setApexDomain($this->otherApex);
        $em->persist($other);
        $em->persist((new ServiceCity())->setSite($other)->setHost($this->otherApex)->setSlug('main')->setIsApex(true)->setNameNomUk('Інше'));

        $this->password = bin2hex(random_bytes(8));
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        foreach (['moderator' => 'ROLE_MODERATOR', 'editor' => 'ROLE_EDITOR'] as $who => $role) {
            $email = $this->slug . '-' . $who . '@example.test';
            $user = (new User())->setEmail($email)->setRoles([$role]);
            $user->setPassword($hasher->hashPassword($user, $this->password));
            $em->persist($user);
            $this->emails[] = $email;
        }
        $em->flush();
        $this->siteIds = [(int) $site->getId(), (int) $other->getId()];
        $this->categoryId = (int) $category->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        foreach ($this->siteIds as $id) {
            $site = $em->find(ServiceSite::class, $id);
            if ($site !== null) {
                $em->remove($site); // міста, категорії — ON DELETE CASCADE
            }
        }
        foreach ($this->emails as $email) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user !== null) {
                $em->remove($user); // токени — ON DELETE CASCADE
            }
        }
        $em->flush();
        parent::tearDown();
    }

    public function testAdminApiOnSiteHostPointsToAdminHost(): void
    {
        foreach ([$this->apex, $this->branch] as $host) {
            $response = $this->http('GET', 'https://' . $host . '/api/admin/v1/me', [], ['HTTP_AUTHORIZATION' => 'Bearer svc_not_a_real_token']);
            self::assertSame(404, $response->getStatusCode(), $host);
            self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
            self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
            $body = $this->decode($response);
            self::assertSame('wrong_host', $body['error']);
            self::assertSame('https://' . $this->adminHost . '/api/admin/v1/me', $body['correct_url']);
            self::assertSame('https://' . $this->adminHost . '/api/admin/v1', $body['admin_api_url']);
            self::assertSame('https://' . $this->adminHost . '/mcp', $body['mcp_url']);
            self::assertStringContainsString($this->adminHost, (string) $body['error_description']);
        }

        $nested = $this->decode($this->http('PATCH', 'https://' . $this->apex . '/api/admin/v1/' . $this->slug . '/categories/' . $this->categoryId));
        self::assertSame('https://' . $this->adminHost . '/api/admin/v1/' . $this->slug . '/categories/' . $this->categoryId, $nested['correct_url'], 'шлях зберігається');

        // адмінка для людей на хості сайту — як і раніше голий 404, без адреси адмінки
        foreach (['/admin', '/login'] as $path) {
            $page = $this->http('GET', 'https://' . $this->apex . $path);
            self::assertSame(404, $page->getStatusCode(), $path);
            self::assertStringNotContainsString($this->adminHost, (string) $page->getContent(), $path);
            self::assertStringNotContainsString('wrong_host', (string) $page->getContent(), $path);
        }
    }

    public function testMcpOnSiteHostIsJsonRpcError(): void
    {
        $response = $this->raw('POST', 'https://' . $this->apex . '/mcp', '{"jsonrpc":"2.0","id":7,"method":"tools/list"}');
        self::assertSame(404, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertSame(7, $body['id'], 'id запиту повертається');
        self::assertSame(-32001, $body['error']['code']);
        self::assertSame('wrong_host', $body['error']['data']['error']);
        self::assertSame('https://' . $this->adminHost . '/mcp', $body['error']['data']['mcp_url']);
        self::assertStringContainsString('https://' . $this->adminHost . '/mcp', $body['error']['message'], 'адреса — і в тексті для людини');
        self::assertStringNotContainsString('svc_', (string) $response->getContent(), 'жодних значень токенів');

        $get = $this->decode($this->http('GET', 'https://' . $this->apex . '/mcp'));
        self::assertNull($get['id']);
        self::assertSame('wrong_host', $get['error']['data']['error']);
    }

    public function testStudioOnAdminHostListsOnlyThisSiteCities(): void
    {
        $response = $this->http('GET', 'https://' . $this->adminHost . '/api/studio/ping');
        self::assertSame(404, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('wrong_host', $body['error']);
        self::assertSame($this->slug, $body['site']);
        $hosts = array_column($body['studio_api_urls'], 'host');
        sort($hosts);
        $want = [$this->apex, $this->branch];
        sort($want);
        self::assertSame($want, $hosts, 'усі активні міста свого сайту');
        self::assertStringNotContainsString($this->otherApex, (string) $response->getContent(), 'чужий сайт не перелічується');
        self::assertSame('https://' . $this->apex . '/api/studio/ping', $body['correct_url'], 'апекс першим, шлях зберігається');
        self::assertContains('https://' . $this->branch . '/api/studio', array_column($body['studio_api_urls'], 'studio_api_url'));
    }

    public function testCorrectHostsAnswerAsBefore(): void
    {
        $me = $this->http('GET', 'https://' . $this->adminHost . '/api/admin/v1/me');
        self::assertSame(401, $me->getStatusCode(), 'REST на хості адмінки без токена — 401');
        self::assertSame('unauthorized', $this->decode($me)['error']);
        self::assertSame(401, $this->raw('POST', 'https://' . $this->adminHost . '/mcp', '{"jsonrpc":"2.0","id":1,"method":"ping"}')->getStatusCode(), 'MCP без токена — 401');
        foreach ([$this->apex, $this->branch] as $host) {
            self::assertSame(401, $this->http('GET', 'https://' . $host . '/api/studio/ping')->getStatusCode(), 'студія на домені міста ' . $host . ' — 401');
        }
    }

    public function testMeDescribesEndpointsAndCapabilities(): void
    {
        $bearer = $this->issue(0, [ApiScope::ContentRead, ApiScope::ContentWrite, ApiScope::ThemeWrite]);
        $response = $this->http('GET', 'https://' . $this->adminHost . '/api/admin/v1/me', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $me = $this->decode($response);
        foreach (['user', 'token', 'sites'] as $key) {
            self::assertArrayHasKey($key, $me, 'старі ключі на місці: ' . $key);
        }
        $site = $this->siteBySlug($me['sites'], $this->slug);
        self::assertSame('https://' . $this->apex . '/api/studio', $site['studio_api_url'], 'старе поле не змінилось');
        self::assertSame('https://' . $this->adminHost . '/api/admin/v1/' . $this->slug, $site['site_api_url']);
        self::assertContains('https://' . $this->branch . '/api/studio', array_column($site['cities'], 'studio_api_url'));

        $ep = $me['endpoints'];
        self::assertSame('https://' . $this->adminHost . '/api/admin/v1', $ep['admin_api_url']);
        self::assertSame('https://' . $this->adminHost . '/mcp', $ep['mcp_url']);
        self::assertSame('https://' . $this->adminHost . '/api/admin/v1/me', $ep['me_url']);
        $epSite = $this->siteBySlug($ep['sites'], $this->slug, 'site');
        self::assertContains($this->branch, array_column($epSite['studio'], 'host'));

        $cap = $me['capabilities'];
        self::assertSame(['content:read' => true, 'content:write' => true, 'prices:write' => false, 'studio:write' => false, 'theme:write' => true], $cap['scopes']);
        self::assertContains('title_uk', $cap['write']['categories'], 'назви категорій — через update');
        self::assertArrayNotHasKey('prices', $cap['write'], 'без prices:write прайс не пишеться');
        self::assertNotContains('image', $cap['write']['items'], 'картинок у полях нема');
        self::assertTrue($cap['theme']['allowed']);
        self::assertSame(['services/**/*.twig', 'partials/**/*.twig', 'theme.css'], $cap['theme']['paths']);
        self::assertFalse($cap['studio']['allowed']);
        self::assertNotEmpty($cap['admin_only']);
        self::assertStringContainsString('фото', implode(' ', $cap['admin_only']));
        self::assertSame([], (array) $cap['inactive_scopes']);
        self::assertStringNotContainsString($bearer, (string) $response->getContent(), 'значення токена у відповіді нема');
    }

    public function testCapabilitiesFollowOwnerRole(): void
    {
        // токен видано, поки роль була; потім роль забрали — дозволи в токені лишились, але не діють (ScopeVoter)
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $this->emails[1]]);
        self::assertInstanceOf(User::class, $user);
        $user->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $bearer = $this->issue(1, [ApiScope::ContentRead, ApiScope::ContentWrite, ApiScope::ThemeWrite]);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $this->emails[1]]);
        self::assertInstanceOf(User::class, $user);
        $user->setRoles(['ROLE_EDITOR']);
        $this->em()->flush();
        $this->em()->clear();

        $me =$this->decode($this->http('GET', 'https://' . $this->adminHost . '/api/admin/v1/me', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer]));
        $cap = $me['capabilities'];
        self::assertSame([false], array_values(array_unique($cap['scopes'])), 'без ROLE_MODERATOR дозволи не діють');
        self::assertSame([], (array) $cap['write']);
        self::assertSame([], $cap['read']);
        self::assertFalse($cap['theme']['allowed']);
        self::assertArrayHasKey('theme:write', (array) $cap['inactive_scopes']);
        self::assertStringContainsString('ROLE_MODERATOR', (string) ((array) $cap['inactive_scopes'])['theme:write']);
    }

    public function testMcpToolsDescribeLimitsAndMeHasCapabilities(): void
    {
        $bearer = $this->issue(0, [ApiScope::ContentRead, ApiScope::ContentWrite]);
        $list = $this->decode($this->raw('POST', 'https://' . $this->adminHost . '/mcp', '{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $bearer));
        $tools = array_column($list['result']['tools'], 'description', 'name');
        self::assertStringContainsString('лише адмінка', $tools['update']);
        self::assertStringContainsString('kind=categories', $tools['update']);
        self::assertStringContainsString('capabilities', $tools['me']);
        self::assertStringContainsString('theme.css', $tools['theme_list']);
        $init = $this->decode($this->raw('POST', 'https://' . $this->adminHost . '/mcp', '{"jsonrpc":"2.0","id":2,"method":"initialize","params":{}}', $bearer));
        self::assertStringContainsString('фото', $init['result']['instructions']);

        $call = $this->decode($this->raw('POST', 'https://' . $this->adminHost . '/mcp', '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"me","arguments":{}}}', $bearer));
        $structured = $call['result']['structuredContent'];
        self::assertSame('https://' . $this->adminHost . '/mcp', $structured['endpoints']['mcp_url']);
        self::assertTrue($structured['capabilities']['scopes']['content:write']);

        // фото через API не змінити: 422 з підказкою «лише в адмінці», а не лише «невідоме поле»
        $patch = $this->decode($this->raw('POST', 'https://' . $this->adminHost . '/mcp', json_encode(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'update', 'arguments' => ['site' => $this->slug, 'kind' => 'categories', 'id' => $this->categoryId, 'fields' => ['image' => 'x.jpg']]]], \JSON_THROW_ON_ERROR), $bearer));
        self::assertTrue($patch['result']['isError']);
        self::assertStringContainsString('лише в адмінці', $patch['result']['content'][0]['text']);
    }

    public function testTokenPageShowsRealAddresses(): void
    {
        $this->login($this->adminHost, $this->emails[0], $this->password);
        $page = $this->http('GET', 'https://' . $this->adminHost . '/admin/admin-api-token');
        self::assertSame(200, $page->getStatusCode());
        $html = html_entity_decode((string) $page->getContent());
        self::assertStringContainsString('id="api-help"', $html);
        self::assertStringContainsString('value="https://' . $this->adminHost . '/api/admin/v1"', $html, 'REST base');
        self::assertStringContainsString('claude mcp add --transport http service-' . $this->slug . ' https://' . $this->adminHost . '/mcp --header "Authorization: Bearer $SVC_TOKEN"', $html);
        self::assertStringContainsString('$env:SVC_TOKEN', $html, 'варіант для PowerShell');
        self::assertStringContainsString('value="https://' . $this->apex . '/api/studio"', $html);
        self::assertStringContainsString('value="https://' . $this->branch . '/api/studio"', $html, 'студія для кожного міста');
        self::assertStringContainsString('data-api-site="' . $this->slug . '"', $html, 'slug сайту');
        self::assertStringNotContainsString($this->otherApex, $html, 'лише сайт цієї адмінки');
        self::assertStringNotContainsString('<сайт>', $html, 'без заглушки');
        self::assertStringContainsString('id="api-admin-only"', $html);
    }

    /** @param list<ApiScope> $scopes */
    private function issue(int $userIndex, array $scopes): string
    {
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $this->emails[$userIndex]]);
        self::assertInstanceOf(User::class, $user);
        $plain = static::getContainer()->get(AdminApiTokens::class)->issue($user, 'ci hints', $scopes, 1, $user)->plain;
        $this->em()->clear();

        return $plain;
    }

    private function raw(string $method, string $url, string $body, ?string $bearer = null): Response
    {
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $server = ['CONTENT_TYPE' => 'application/json'] + ($bearer !== null ? ['HTTP_AUTHORIZATION' => 'Bearer ' . $bearer] : []);
        $request = Request::create($url, $method, [], [], [], $server, $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data, (string) $response->getContent());

        return $data;
    }

    /**
     * @param list<array<string, mixed>> $sites
     *
     * @return array<string, mixed>
     */
    private function siteBySlug(array $sites, string $slug, string $key = 'slug'): array
    {
        foreach ($sites as $s) {
            if (($s[$key] ?? null) === $slug) {
                return $s;
            }
        }
        self::fail('сайту ' . $slug . ' нема у відповіді');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
