<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Admin\CopyUrlField;
use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Кнопка «скопіювати адресу» (08.11.2026): у списках «Сайти-бренди» і «Міста сервісів» біля домену/хоста — кнопка з
 * data-copy="https://<домен>/", у формі — input[data-copy-url] (кнопку додає admin-copy-url.js), JS підключено в адмінці.
 */
final class AdminCopyUrlTest extends HttpKernelTestCase
{
    private string $apex;
    private string $adminHost;
    private int $siteId;
    private string $email;
    private string $password;

    protected function setUp(): void
    {
        $slug = 'ci-copy-' . bin2hex(random_bytes(3));
        $this->apex = $slug . '.example';
        $this->adminHost = 'admin.' . $this->apex;
        $_SERVER['ADMIN_HOST'] = $_ENV['ADMIN_HOST'] = $this->adminHost;
        putenv('ADMIN_HOST=' . $this->adminHost);
        self::bootKernel();
        $em = $this->em();
        $site = (new ServiceSite())->setSlug($slug)->setName('CI Copy')->setApexDomain($this->apex);
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost('kyiv.' . $this->apex)->setSlug('kyiv')->setIsApex(false)->setNameNomUk('Київ'));
        $this->password = bin2hex(random_bytes(8));
        $this->email = $slug . '-admin@example.test';
        $user = (new User())->setEmail($this->email)->setRoles(['ROLE_ADMIN']);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $this->password));
        $em->persist($user);
        $em->flush();
        $this->siteId = (int) $site->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        $site = $em->find(ServiceSite::class, $this->siteId);
        if (null !== $site) {
            $em->remove($site);
        }
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->email]);
        if (null !== $user) {
            $em->remove($user);
        }
        $em->flush();
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    public function testCopyButtonsNextToDomainsAndHosts(): void
    {
        $this->login($this->adminHost, $this->email, $this->password);
        $sites = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-site')->getContent();
        self::assertStringContainsString('data-copy="https://' . $this->apex . '/"', $sites, 'список брендів: кнопка біля домену');
        self::assertStringContainsString('aria-label="Скопіювати адресу сайту"', $sites);
        self::assertStringContainsString('assets/admin-copy-url.js', $sites, 'JS кнопки підключено');
        self::assertStringNotContainsString('<!--', (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/' . CopyUrlField::TEMPLATE), 'шаблон поля — лише {# #}');

        $cities = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-city')->getContent();
        self::assertStringContainsString('data-copy="https://kyiv.' . $this->apex . '/"', $cities, 'список міст: кнопка біля хоста');

        self::assertSame(1, preg_match('~href="([^"]*/admin/service-site/' . $this->siteId . '/edit[^"]*)"~', $sites, $m), 'посилання на редагування бренду');
        $edit = (string) $this->http('GET', str_starts_with($m[1], 'http') ? html_entity_decode($m[1]) : 'https://' . $this->adminHost . html_entity_decode($m[1]))->getContent();
        self::assertMatchesRegularExpression('~<input[^>]*name="ServiceSite\[apexDomain\]"[^>]*data-copy-url="1"|<input[^>]*data-copy-url="1"[^>]*name="ServiceSite\[apexDomain\]"~', $edit, 'форма: поле домену з data-copy-url');
    }

    public function testUrlFromDomainOrHost(): void
    {
        self::assertSame('https://example.com/', CopyUrlField::url(' Example.com. '));
        self::assertSame('https://kyiv.example.com/', CopyUrlField::url('https://kyiv.example.com/path?x=1'));
        self::assertNull(CopyUrlField::url(''));
        self::assertNull(CopyUrlField::url(null));
    }
}
