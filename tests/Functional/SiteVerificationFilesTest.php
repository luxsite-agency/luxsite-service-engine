<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Entity\ServiceSiteSetting;
use App\Entity\User;
use App\Settings\SecretBox;
use App\Settings\SiteSettingKeys as K;
use App\Settings\SiteSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Файли підтвердження Google Search Console (і BingSiteAuth.xml) — окремо на кожен бренд з адмінки: файл віддається
 * лише на хостах бренду, якому його задано (апекс і міста), на інших — 404; спільного файла в public рушія нема.
 */
final class SiteVerificationFilesTest extends HttpKernelTestCase
{
    /** Ім'я з брифу «google111aaa.html» коротше за правило (8–32 hex) — тут те саме за змістом, але валідне. */
    private const string FILE_A = 'google111aaa222bbb.html';
    private const string BING_A = '0123456789ABCDEF0123456789ABCDEF';

    private string $apexA;
    private string $branchA;
    private string $apexB;
    private string $adminHost;
    private int $siteA;
    private int $siteB;
    private string $adminEmail;
    private string $password;

    protected function setUp(): void
    {
        $tag = bin2hex(random_bytes(3));
        $this->apexA = 'ci-gsc-a-' . $tag . '.test';
        $this->branchA = 'kyiv.' . $this->apexA;
        $this->apexB = 'ci-gsc-b-' . $tag . '.test';
        $this->adminHost = 'admin.' . $this->apexA;
        foreach (['SETTINGS_SECRET_KEY' => SecretBox::generateKey(), 'ADMIN_HOST' => $this->adminHost] as $k => $v) {
            $_SERVER[$k] = $_ENV[$k] = $v;
            putenv($k . '=' . $v);
        }
        self::bootKernel();

        $em = $this->em();
        $a = (new ServiceSite())->setSlug('ci-gsc-a-' . $tag)->setName('CI GSC A')->setApexDomain($this->apexA);
        $b = (new ServiceSite())->setSlug('ci-gsc-b-' . $tag)->setName('CI GSC B')->setApexDomain($this->apexB);
        $em->persist($a);
        $em->persist($b);
        $em->persist((new ServiceCity())->setSite($a)->setHost($this->apexA)->setSlug('main')->setIsApex(true)->setNameNomUk('Головне'));
        $em->persist((new ServiceCity())->setSite($a)->setHost($this->branchA)->setSlug('kyiv')->setNameNomUk('Київ'));
        $em->persist((new ServiceCity())->setSite($b)->setHost($this->apexB)->setSlug('main')->setIsApex(true)->setNameNomUk('Головне'));
        $this->password = bin2hex(random_bytes(8));
        $this->adminEmail = 'ci-gsc-' . $tag . '-admin@example.test';
        $user = (new User())->setEmail($this->adminEmail)->setRoles(['ROLE_ADMIN']);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $this->password));
        $em->persist($user);
        $em->flush();
        $this->siteA = (int) $a->getId();
        $this->siteB = (int) $b->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        foreach ([$this->siteA, $this->siteB] as $id) {
            $site = $em->find(ServiceSite::class, $id);
            if (null !== $site) {
                $em->remove($site); // міста й налаштування — ON DELETE CASCADE
            }
        }
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->adminEmail]);
        if (null !== $user) {
            $em->remove($user);
        }
        $em->flush();
        parent::tearDown();
    }

    public function testFileIsServedOnlyOnHostsOfItsBrand(): void
    {
        $this->set($this->siteA, [K::GOOGLE_VERIFICATION_FILES => self::FILE_A]);

        foreach ([$this->apexA, $this->branchA] as $host) {
            $r = $this->http('GET', 'https://' . $host . '/' . self::FILE_A);
            self::assertSame(200, $r->getStatusCode(), $host);
            self::assertSame('google-site-verification: ' . self::FILE_A, $r->getContent(), 'тіло — як у файлі від Google');
            self::assertStringStartsWith('text/html', (string) $r->headers->get('Content-Type'));
            $maxAge = $r->getMaxAge();
            self::assertNotNull($maxAge, 'короткий кеш задано явно');
            self::assertLessThanOrEqual(600, $maxAge, 'видалений в адмінці файл не живе в кеші CDN довго');
        }

        self::assertSame(404, $this->http('GET', 'https://' . $this->apexB . '/' . self::FILE_A)->getStatusCode(), 'чужий бренд — 404');
        self::assertSame(404, $this->http('GET', 'https://' . $this->apexA . '/google999aaa999bbb.html')->getStatusCode(), 'не заданий бренду файл — 404');
        self::assertSame(404, $this->http('GET', 'https://' . $this->adminHost . '/' . self::FILE_A)->getStatusCode(), 'хост адмінки — не сайт');
    }

    public function testEngineHasNoSharedVerificationFile(): void
    {
        self::assertFileDoesNotExist(\dirname(__DIR__, 2) . '/public/google3cc17ecbb1f93a59.html', 'спільний токен зв\'язував мережу');
        self::assertSame([], glob(\dirname(__DIR__, 2) . '/public/google*.html') ?: [], 'жодного файла Google у public рушія');
        foreach ([$this->apexA, $this->apexB] as $host) {
            self::assertSame(404, $this->http('GET', 'https://' . $host . '/google3cc17ecbb1f93a59.html')->getStatusCode(), $host);
        }
    }

    public function testAdminRejectsBadNamesAndSavedNameWorksWithoutDeploy(): void
    {
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $edit = $this->editUrl($this->siteA, K::GOOGLE_VERIFICATION_FILES);

        foreach (['google../x.html', 'evil.html', 'google11111111.html google22222222.html google33333333.html google44444444.html google55555555.html google66666666.html'] as $bad) {
            $r = $this->submit($edit, ['ServiceSiteSetting[value]' => $bad]);
            self::assertSame(422, $r->getStatusCode(), 'помилка валідації: ' . $bad);
            self::assertNull($this->stored($this->siteA, K::GOOGLE_VERIFICATION_FILES), 'нічого не збережено: ' . $bad);
        }
        self::assertSame(404, $this->http('GET', 'https://' . $this->apexA . '/' . self::FILE_A)->getStatusCode(), 'ще не задано');

        // вставили повну адресу з GSC — зберігається лише ім'я
        self::assertTrue($this->submit($edit, ['ServiceSiteSetting[value]' => 'https://' . $this->apexA . '/' . self::FILE_A])->isRedirect(), 'збережено');
        self::assertSame(self::FILE_A, $this->stored($this->siteA, K::GOOGLE_VERIFICATION_FILES));

        // без деплою й без чистки кешу: той самий процес ядра, наступний запит
        self::assertSame(200, $this->http('GET', 'https://' . $this->apexA . '/' . self::FILE_A)->getStatusCode());
        self::assertSame(404, $this->http('GET', 'https://' . $this->apexB . '/' . self::FILE_A)->getStatusCode());

        // прибрали в адмінці — одразу 404
        self::assertTrue($this->submit($edit, ['ServiceSiteSetting[value]' => ''])->isRedirect());
        self::assertSame(404, $this->http('GET', 'https://' . $this->apexA . '/' . self::FILE_A)->getStatusCode());
    }

    public function testBingSiteAuthPerBrand(): void
    {
        self::assertSame(404, $this->http('GET', 'https://' . $this->apexA . '/BingSiteAuth.xml')->getStatusCode(), 'не задано — 404');
        $this->set($this->siteA, [K::BING_SITE_AUTH => self::BING_A]);

        $r = $this->http('GET', 'https://' . $this->branchA . '/BingSiteAuth.xml');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('application/xml', (string) $r->headers->get('Content-Type'));
        self::assertSame("<?xml version=\"1.0\"?>\n<users>\n\t<user>" . self::BING_A . "</user>\n</users>\n", $r->getContent());
        self::assertSame(404, $this->http('GET', 'https://' . $this->apexB . '/BingSiteAuth.xml')->getStatusCode(), 'чужий бренд — 404');
    }

    public function testPagesWithoutSettingsUnchanged(): void
    {
        $this->set($this->siteA, [K::GOOGLE_VERIFICATION_FILES => self::FILE_A, K::BING_SITE_AUTH => self::BING_A]);
        $page = (string) $this->http('GET', 'https://' . $this->apexA . '/reviews/')->getContent();
        self::assertStringNotContainsString(self::FILE_A, $page, 'файл підтвердження — не мета-тег, у сторінку не потрапляє');
        self::assertStringNotContainsString(self::BING_A, $page);
        self::assertStringNotContainsString('<!--', $page);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @param array<string, string> $values */
    private function set(int $siteId, array $values): void
    {
        $settings = static::getContainer()->get(SiteSettings::class);
        $site = $this->em()->find(ServiceSite::class, $siteId);
        self::assertNotNull($site);
        foreach ($values as $code => $value) {
            $settings->set($site, $code, $value);
        }
        $this->em()->clear();
    }

    private function stored(int $siteId, string $code): ?string
    {
        $this->em()->clear();

        return $this->em()->getRepository(ServiceSiteSetting::class)->findOneBy(['site' => $siteId, 'code' => $code])?->getValue();
    }

    private function editUrl(int $siteId, string $code): string
    {
        $html = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting?site=' . $siteId)->getContent();
        self::assertSame(1, preg_match('/<a href="([^"]+)" data-setting-edit="' . preg_quote($code, '/') . '"/', $html, $m), 'посилання на редагування ' . $code);
        $url = html_entity_decode($m[1]);

        return str_starts_with($url, 'http') ? $url : 'https://' . $this->adminHost . $url;
    }

    /** @param array<string, string> $values */
    private function submit(string $editUrl, array $values): Response
    {
        $form = (string) $this->http('GET', $editUrl)->getContent();
        $fields = array_replace(self::formFields($form, 'ServiceSiteSetting'), $values);

        return $this->http('POST', $editUrl, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]]);
    }
}
