<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Бренди користувача й токена (22.10.2026): студія на адмін-хості /api/studio/<slug>/… і REST пускають лише до брендів
 * токена; спільний STUDIO_API_TOKEN через адмін-хост не працює; видача токена не розширює бренди користувача; зовнішні
 * посилання статті — nofollow, посилання на хости бренду — ні.
 */
final class BrandAccessTest extends HttpKernelTestCase
{
    private string $admin;
    /** @var array<string, string> бренд → slug */
    private array $slugs = [];
    /** @var list<int> */
    private array $siteIds = [];
    private string $email;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = (string) ($_SERVER['ADMIN_HOST'] ?? getenv('ADMIN_HOST') ?: 'admin.ci.local');
        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        foreach (['a', 'b'] as $k) {
            $slug = 'ci-acc-' . $k . '-' . $suffix;
            $this->slugs[$k] = $slug;
            $site = (new ServiceSite())->setSlug($slug)->setName('CI ' . $k)->setApexDomain($slug . '.example');
            $em->persist($site);
            $em->persist((new ServiceCity())->setSite($site)->setHost($slug . '.example')->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
            $em->flush();
            $this->siteIds[] = (int) $site->getId();
        }
        $this->email = 'ci-acc-' . $suffix . '@example.com';
        $user = (new User())->setEmail($this->email)->setRoles(['ROLE_MODERATOR'])->setPassword('x')->setSites([$this->slugs['a']]);
        $em->persist($user);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE t FROM admin_api_token t JOIN `user` u ON u.id = t.user_id WHERE u.email = ?', [$this->email]);
        $conn->executeStatement('DELETE FROM `user` WHERE email = ?', [$this->email]);
        foreach ($this->siteIds as $id) {
            $conn->executeStatement('DELETE FROM service_site WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    public function testTokenIsLimitedToUserBrands(): void
    {
        /** @var AdminApiTokens $tokens */
        $tokens = self::getContainer()->get(AdminApiTokens::class);
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $this->email]) ?? self::fail('користувач є');

        try {
            $tokens->issue($user, 'зайвий бренд', [ApiScope::StudioWrite], 7, $user, [$this->slugs['b']]);
            self::fail('бренд поза User.sites — відмова');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $issued = $tokens->issue($user, 'студія', [ApiScope::StudioWrite, ApiScope::ContentRead], 7, $user);
        self::assertSame([$this->slugs['a']], $issued->token->getSites(), 'токен без списку отримує бренди користувача');
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $issued->plain, 'CONTENT_TYPE' => 'application/json'];

        $body = (string) json_encode([
            'lang' => 'uk', 'group_id' => '00000000-0000-4000-8000-000000000001', 'title' => 'Стаття', 'slug' => 'stattia',
            'body_html' => '<p><a href="https://other.example/x">зовнішнє</a> і <a href="//proto.example/y">без схеми</a> і <a href="https://' . $this->slugs['a'] . '.example/z">своє</a></p>',
        ]);
        $ok = $this->send('PUT', 'https://' . $this->admin . '/api/studio/' . $this->slugs['a'] . '/articles/x1', $body, $auth);
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getContent());
        $denied = $this->send('PUT', 'https://' . $this->admin . '/api/studio/' . $this->slugs['b'] . '/articles/x1', $body, $auth);
        self::assertSame(403, $denied->getStatusCode(), 'чужий бренд — 403');
        self::assertSame(404, $this->send('GET', 'https://' . $this->admin . '/api/studio/ci-nema/ping', '', $auth)->getStatusCode());

        $this->em()->clear();
        $post = $this->em()->getRepository(ServicePost::class)->findOneBy(['slugUk' => 'stattia']) ?? self::fail('стаття є');
        $html = (string) $post->getDescUk();
        self::assertStringContainsString('href="https://other.example/x" rel="nofollow noopener" target="_blank"', $html);
        self::assertStringContainsString('href="//proto.example/y" rel="nofollow noopener"', $html, 'protocol-relative теж зовнішнє');
        self::assertStringContainsString('href="https://' . $this->slugs['a'] . '.example/z">', $html, 'хост бренду — без nofollow, хоч запит і з адмін-хоста');

        $rest = $this->send('GET', 'https://' . $this->admin . '/api/admin/v1/' . $this->slugs['b'] . '/posts', '', $auth);
        self::assertSame(403, $rest->getStatusCode(), 'REST: чужий бренд — 403');
        $me = json_decode((string) $this->send('GET', 'https://' . $this->admin . '/api/admin/v1/me', '', $auth)->getContent(), true);
        self::assertSame([$this->slugs['a']], array_values(array_intersect(array_column($me['sites'] ?? [], 'slug'), array_values($this->slugs))), '/me — лише свої бренди');
    }

    public function testSharedStudioTokenIsNotAcceptedViaAdminHost(): void
    {
        $r = $this->send('GET', 'https://' . $this->admin . '/api/studio/' . $this->slugs['a'] . '/ping', '', ['HTTP_AUTHORIZATION' => 'Bearer some-shared-token-value']);
        self::assertSame(401, $r->getStatusCode());
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
