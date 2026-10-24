<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServicePostCategory;
use App\Entity\ServiceSite;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Рубрики з панелі (24.10.2026, новинні бренди): category_slug наявної рубрики — стаття в ній; нової — рубрика
 * створюється активною з назвою category_name (повтор не дублює), без назви — 422; слаг фіксованого розділу при
 * category_url: root — 422. /pages контентного бренду віддає рубрики з адресами від кореня.
 */
final class StudioCategoryTest extends HttpKernelTestCase
{
    private const SLUG = 'ci-studiocat';

    private string $admin;
    private string $host;
    private string $email;
    private int $siteId = 0;
    /** @var array<string, string> */
    private array $auth = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = (string) ($_SERVER['ADMIN_HOST'] ?? getenv('ADMIN_HOST') ?: 'admin.ci.local');
        $em = $this->em();
        $em->getConnection()->executeStatement('DELETE FROM service_site WHERE slug = ?', [self::SLUG]); // після обірваного прогону
        $suffix = bin2hex(random_bytes(3));
        $this->host = self::SLUG . '-' . $suffix . '.example';
        $site = (new ServiceSite())->setSlug(self::SLUG)->setName('CI studio')->setApexDomain($this->host)->setMode(ServiceSite::MODE_CONTENT)->setBlogPrefix('novyny');
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->host)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        $em->persist((new ServicePostCategory())->setSite($site)->setSlugUk('politics')->setTitleUk('Політика'));
        $this->email = 'ci-studiocat-' . $suffix . '@example.com';
        $user = (new User())->setEmail($this->email)->setRoles(['ROLE_MODERATOR'])->setPassword('x')->setSites([self::SLUG]);
        $em->persist($user);
        $em->flush();
        $this->siteId = (int) $site->getId();

        /** @var AdminApiTokens $tokens */
        $tokens = self::getContainer()->get(AdminApiTokens::class);
        $issued = $tokens->issue($user, 'студія', [ApiScope::StudioWrite], 7, $user, [self::SLUG]);
        $this->auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $issued->plain, 'CONTENT_TYPE' => 'application/json'];
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE t FROM admin_api_token t JOIN `user` u ON u.id = t.user_id WHERE u.email = ?', [$this->email]);
        $conn->executeStatement('DELETE FROM `user` WHERE email = ?', [$this->email]);
        $conn->executeStatement('DELETE FROM service_site WHERE id = ?', [$this->siteId]);
        parent::tearDown();
    }

    public function testCategoryIsCreatedFromStudio(): void
    {
        $existing = $this->put('a1', '00000000-0000-4000-8000-0000000000a1', 'stattia-pro-politiku', ['category_slug' => 'politics', 'category_name' => 'Інша назва']);
        self::assertSame(201, $existing->getStatusCode(), (string) $existing->getContent());
        self::assertSame('politics', $this->json($existing)['category'] ?? null);

        $created = $this->put('a2', '00000000-0000-4000-8000-0000000000a2', 'tramp-zaiava', ['category_slug' => 'donald-tramp', 'category_name' => 'Дональд Трамп']);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        self::assertSame('donald-tramp', $this->json($created)['category'] ?? null);
        $again = $this->put('a2', '00000000-0000-4000-8000-0000000000a2', 'tramp-zaiava', ['category_slug' => 'donald-tramp', 'category_name' => 'Дональд Трамп']);
        self::assertSame(200, $again->getStatusCode(), 'повтор — та сама стаття');
        self::assertSame($this->json($created)['id'] ?? null, $this->json($again)['id'] ?? null);

        $noName = $this->put('a3', '00000000-0000-4000-8000-0000000000a3', 'zelenskyi', ['category_slug' => 'volodymyr-zelenskyi']);
        self::assertSame(422, $noName->getStatusCode());
        self::assertArrayHasKey('category_name', $this->json($noName)['fields'] ?? []);
        $reserved = $this->put('a4', '00000000-0000-4000-8000-0000000000a4', 'kontakty', ['category_slug' => 'contacts', 'category_name' => 'Контакти']);
        self::assertSame(422, $reserved->getStatusCode(), 'слаг фіксованого розділу при рубриках від кореня');
        self::assertArrayHasKey('category_slug', $this->json($reserved)['fields'] ?? []);

        $this->em()->clear();
        $site = $this->em()->find(ServiceSite::class, $this->siteId) ?? self::fail('бренд є');
        $cats = $this->em()->getRepository(ServicePostCategory::class)->findBy(['site' => $site], ['id' => 'ASC']);
        self::assertSame(['politics', 'donald-tramp'], array_map(static fn (ServicePostCategory $c): string => $c->slug('uk'), $cats), 'без дублів і без рубрик з 422');
        self::assertSame('Політика', $cats[0]->getTitleUk(), 'назву наявної рубрики панель не переписує');
        self::assertSame('Дональд Трамп', $cats[1]->getTitleUk());
        self::assertTrue($cats[1]->isActive());
        self::assertGreaterThan($cats[0]->getPosition(), $cats[1]->getPosition(), 'нова — в кінці меню');
        $posts = $this->em()->getRepository(ServicePost::class)->findBy(['site' => $site]);
        self::assertCount(2, $posts, 'з 422 стаття не створюється');
        $post = $this->em()->getRepository(ServicePost::class)->findOneBy(['site' => $site, 'slugUk' => 'tramp-zaiava']) ?? self::fail('стаття є');
        self::assertSame('donald-tramp', $post->getCategory()?->slug('uk'));

        self::assertSame(200, $this->send('GET', 'https://' . $this->host . '/donald-tramp/', '', [])->getStatusCode(), 'нова рубрика відкривається від кореня');
        $pages = $this->json($this->send('GET', 'https://' . $this->admin . '/api/studio/' . self::SLUG . '/pages', '', $this->auth))['pages'] ?? [];
        // ru у розгортанні з uk+ru — фолбеком на uk, як у постів; перевіряємо uk
        $categories = array_column(array_filter($pages, static fn (array $p): bool => $p['type'] === 'category' && $p['lang'] === 'uk'), 'title', 'url');
        self::assertSame(['/politics/' => 'Політика', '/donald-tramp/' => 'Дональд Трамп'], $categories, '/pages — рубрики бренду від кореня');
    }

    /** @param array<string, string> $extra */
    private function put(string $externalId, string $group, string $slug, array $extra): \Symfony\Component\HttpFoundation\Response
    {
        $body = (string) json_encode(['lang' => 'uk', 'group_id' => $group, 'title' => 'Новина ' . $slug, 'slug' => $slug, 'body_html' => '<p>Текст новини.</p>'] + $extra);

        return $this->send('PUT', 'https://' . $this->admin . '/api/studio/' . self::SLUG . '/articles/' . $externalId, $body, $this->auth);
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /** @param array<string, string> $server */
    private function send(string $method, string $url, string $body, array $server): \Symfony\Component\HttpFoundation\Response
    {
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $request = \Symfony\Component\HttpFoundation\Request::create($url, $method, [], [], [], $server, $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
