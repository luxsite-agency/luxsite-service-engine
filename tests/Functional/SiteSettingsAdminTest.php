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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Розділ «Налаштування сайту» (SiteSettingCrudController) і те, що він дає сайту: доступ лише адміністратору, формат id,
 * секрет шифрується, аудит без значень, «Вхід через Google» з адресами для кожного міста й ключами з адмінки, теги на
 * сторінках міст (лічильник міста > GTM сайту), назва адмінки. Сайт без налаштувань — без нових скриптів.
 */
final class SiteSettingsAdminTest extends HttpKernelTestCase
{
    private const string CLIENT_ID = '123456789012-abcdefgh12345678.apps.googleusercontent.com';

    private string $slug;
    private string $apex;
    private string $branch;
    private string $adminHost;
    private int $siteId;
    private string $adminEmail;
    private string $moderatorEmail;
    private string $password;

    protected function setUp(): void
    {
        $this->slug = 'ci-settings-' . bin2hex(random_bytes(3));
        $this->apex = $this->slug . '.example';
        $this->branch = 'branch.' . $this->apex;
        $this->adminHost = 'admin.' . $this->apex;
        // ключ шифрування й хост адмінки — лише для цього процесу; реальних ключів у тестах нема
        foreach (['SETTINGS_SECRET_KEY' => SecretBox::generateKey(), 'ADMIN_HOST' => $this->adminHost, 'GOOGLE_CLIENT_ID' => '', 'GOOGLE_CLIENT_SECRET' => ''] as $k => $v) {
            $_SERVER[$k] = $_ENV[$k] = $v;
            putenv($k . '=' . $v);
        }
        self::bootKernel();

        $em = $this->em();
        $site = (new ServiceSite())->setSlug($this->slug)->setName('CI Settings Site')->setApexDomain($this->apex);
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->apex)->setSlug('main')->setIsApex(true)->setNameNomUk('Головне')->setGtmId('GTM-CITY111'));
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->branch)->setSlug('branch')->setNameNomUk('Філія'));
        $this->password = bin2hex(random_bytes(8));
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->adminEmail = $this->slug . '-admin@example.test';
        $this->moderatorEmail = $this->slug . '-moderator@example.test';
        foreach ([$this->adminEmail => 'ROLE_ADMIN', $this->moderatorEmail => 'ROLE_MODERATOR'] as $email => $role) {
            $user = (new User())->setEmail($email)->setRoles([$role]);
            $user->setPassword($hasher->hashPassword($user, $this->password));
            $em->persist($user);
        }
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
            $em->remove($site); // міста й налаштування — ON DELETE CASCADE
        }
        foreach ([$this->adminEmail, $this->moderatorEmail] as $email) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null !== $user) {
                $em->remove($user);
            }
        }
        $em->flush();
        parent::tearDown();
    }

    public function testSiteWithoutSettingsHasNoNewScripts(): void
    {
        $apex = (string) $this->http('GET', 'https://' . $this->apex . '/reviews/')->getContent();
        self::assertStringContainsString("'dataLayer','GTM-CITY111'", $apex, 'лічильник міста — як раніше');
        self::assertStringContainsString('ns.html?id=GTM-CITY111', $apex);
        foreach (['svTags', 'fbq(', 'msvalidate.01', 'sv-consent', "gtag('consent'", '/connect/google'] as $marker) {
            self::assertStringNotContainsString($marker, $apex, $marker);
        }
        $branch = (string) $this->http('GET', 'https://' . $this->branch . '/reviews/')->getContent();
        self::assertStringNotContainsString('googletagmanager.com', $branch, 'місто без лічильника й сайт без налаштувань — без тегів');
        $thanks = (string) $this->http('GET', 'https://' . $this->apex . '/thank-you/?src=form')->getContent();
        self::assertStringNotContainsString('svTags', $thanks);
    }

    public function testSettingsReachThePages(): void
    {
        $this->set([
            K::GTM_CONTAINER_ID => 'GTM-SITE111',
            K::CLARITY_ID => 'abcde12345',
            K::FB_PIXEL_ID => '1234567890',
            K::CONSENT_BANNER => '1',
            K::BING_SITE_VERIFICATION => '0123456789ABCDEF',
            K::GOOGLE_SITE_VERIFICATION => 'site-verification-123',
            K::HTML_HEAD => '<meta name="ci-head" content="1">',
            K::HTML_BODY_END => '<div id="ci-body-end"></div>',
        ]);

        $apex = (string) $this->http('GET', 'https://' . $this->apex . '/reviews/')->getContent();
        self::assertStringContainsString("'dataLayer','GTM-CITY111'", $apex, 'лічильник міста має пріоритет');
        self::assertStringNotContainsString('GTM-SITE111', $apex, 'GTM сайту в місті з власним лічильником не виводиться');

        $branch = (string) $this->http('GET', 'https://' . $this->branch . '/reviews/')->getContent();
        self::assertStringContainsString("'dataLayer','GTM-SITE111'", $branch, 'місто без лічильника — GTM сайту');
        self::assertStringContainsString('ns.html?id=GTM-SITE111', $branch);
        self::assertStringContainsString('"abcde12345"', $branch, 'Clarity з налаштувань');
        self::assertStringContainsString("fbq('init','1234567890')", $branch);
        self::assertStringContainsString("svTags.run('consent'", $branch, 'з банером Clarity/Pixel чекають згоди');
        self::assertStringContainsString("gtag('consent','default'", $branch);
        self::assertLessThan(strpos($branch, 'gtm.js?id='), strpos($branch, "gtag('consent','default'"), 'consent default — до GTM');
        self::assertStringContainsString('id="sv-consent"', $branch);
        self::assertStringContainsString('<meta name="msvalidate.01" content="0123456789ABCDEF">', $branch);
        self::assertStringContainsString('<meta name="google-site-verification" content="site-verification-123">', $branch);
        self::assertStringContainsString('<meta name="ci-head" content="1">', $branch);
        self::assertStringContainsString('<div id="ci-body-end"></div>', $branch);

        $thanks = (string) $this->http('GET', 'https://' . $this->branch . '/thank-you/?src=form')->getContent();
        self::assertStringContainsString("svTags.run('fbq',function(){fbq('track','Lead');});", $thanks);
        $dup = (string) $this->http('GET', 'https://' . $this->branch . '/thank-you/?src=dup')->getContent();
        self::assertStringNotContainsString("fbq('track','Lead')", $dup, 'повторна заявка — без конверсії');
    }

    public function testGa4AndAdsWithoutGtmSendLeadEvents(): void
    {
        $this->set([K::GA4_MEASUREMENT_ID => 'G-SITE22222', K::GOOGLE_ADS_ID => 'AW-123456789', K::GOOGLE_ADS_LEAD_LABEL => 'LeadLabel_1']);
        $branch = (string) $this->http('GET', 'https://' . $this->branch . '/reviews/')->getContent();
        self::assertStringContainsString('gtag/js?id=G-SITE22222', $branch);
        self::assertStringContainsString("gtag('config','G-SITE22222');gtag('config','AW-123456789');svTags.go('gtag');", str_replace(' ', '', $branch));

        $thanks = (string) $this->http('GET', 'https://' . $this->branch . '/thank-you/?src=calc')->getContent();
        self::assertStringContainsString('"send_to":"AW-123456789/LeadLabel_1"', $thanks);
        self::assertStringContainsString('"send_to":"G-SITE22222","lead_type":"calculator"', $thanks);
        $apex = (string) $this->http('GET', 'https://' . $this->apex . '/thank-you/?src=form')->getContent();
        self::assertStringNotContainsString('AW-123456789', $apex, 'місто з GTM: GA4/Ads сайту не виводяться');
    }

    public function testAdminOnlyAndTokensDoNotReachSettings(): void
    {
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        self::assertSame(403, $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting')->getStatusCode(), 'модератор — 403');

        // Bearer-токен менеджера не автентифікує адмінку (firewall admin_api лише для /api/admin і /mcp)
        $anon = $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting', [], ['HTTP_AUTHORIZATION' => 'Bearer svc_not_a_real_token']);
        self::assertTrue($anon->isRedirect() || 403 === $anon->getStatusCode() || 401 === $anon->getStatusCode());
        self::assertSame(404, $this->http('GET', 'https://' . $this->apex . '/admin/site-setting')->getStatusCode(), 'на хості сайту адмінки нема');
    }

    public function testIndexShowsGooglePanelForEveryCityAndAdminTitle(): void
    {
        $login = (string) $this->http('GET', 'https://' . $this->adminHost . '/login')->getContent();
        self::assertStringContainsString('<h1 style="font-size:1.4rem">CI Settings Site</h1>', $login, 'назва адмінки — назва сайту хоста адмінки');
        self::assertStringContainsString('<title>Вхід - CI Settings Site</title>', $login);

        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $dashboard = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin')->getContent();
        self::assertStringContainsString('data-google-warning', $dashboard, 'жовте попередження є');
        self::assertStringContainsString('#google-sign-in', $dashboard, '…і веде на розділ налаштувань');

        $index = $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting?site=' . $this->siteId);
        self::assertSame(200, $index->getStatusCode());
        $html = (string) $index->getContent();
        foreach (K::all() as $code) {
            self::assertStringContainsString('data-setting-edit="' . $code . '"', $html, $code);
        }
        self::assertStringContainsString('value="https://' . $this->apex . '/connect/google/check"', $html);
        self::assertStringContainsString('value="https://' . $this->branch . '/connect/google/check"', $html, 'redirect URI для кожного міста');
        self::assertStringContainsString('value="https://' . $this->branch . '"', $html, 'JavaScript origin міста');
        self::assertStringContainsString('data-google-source="none"', $html);
        self::assertStringContainsString('data-city-host="' . $this->apex . '"', $html);
        self::assertStringContainsString('data-city-edit="' . $this->branch . '"', $html, 'посилання на поле міста');
    }

    public function testEditValidatesNormalizesAndAuditsWithoutValues(): void
    {
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $edit = $this->editUrl(K::GTM_CONTAINER_ID);

        $bad = $this->submit($edit, ['ServiceSiteSetting[value]' => 'not a container']);
        self::assertSame(422, $bad->getStatusCode(), 'невірний формат — помилка біля поля');
        self::assertNull($this->stored(K::GTM_CONTAINER_ID));

        $ok = $this->submit($edit, ['ServiceSiteSetting[value]' => "<script>(function(w,d,s,l,i){})(window,document,'script','dataLayer','GTM-ABC1234');</script>"]);
        self::assertTrue($ok->isRedirect(), 'збережено');
        self::assertSame('GTM-ABC1234', $this->stored(K::GTM_CONTAINER_ID), 'зі вставленого коду — лише id');

        $log = $this->auditLog();
        self::assertStringContainsString('"event":"settings.changed","site_id":' . $this->siteId . ',"code":"' . K::GTM_CONTAINER_ID . '"', $log);
        self::assertStringNotContainsString('GTM-ABC1234', $log, 'значення в журнал не пишемо');
        self::assertStringNotContainsString($this->adminEmail, $log, 'email — лише HMAC');
    }

    public function testSecretIsEncryptedMaskedAndClearable(): void
    {
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $plain = 'test-only-client-secret-wxyz';
        $edit = $this->editUrl(K::GOOGLE_CLIENT_SECRET);
        self::assertTrue($this->submit($edit, ['ServiceSiteSetting[value]' => $plain])->isRedirect());

        $stored = (string) $this->stored(K::GOOGLE_CLIENT_SECRET);
        self::assertStringStartsWith('enc:v1:wxyz:', $stored, 'у БД — конверт');
        self::assertStringNotContainsString($plain, $stored);
        $index = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting?site=' . $this->siteId)->getContent();
        self::assertStringNotContainsString($plain, $index);
        self::assertStringContainsString('…wxyz', $index, 'у списку — маска');
        $form = (string) $this->http('GET', $edit)->getContent();
        self::assertStringNotContainsString($plain, $form, 'поле пароля порожнє');

        // порожнє поле при збереженні — лишити чинний
        self::assertTrue($this->submit($edit, ['ServiceSiteSetting[value]' => ''])->isRedirect());
        self::assertSame($stored, $this->stored(K::GOOGLE_CLIENT_SECRET));

        $log = $this->auditLog();
        self::assertStringContainsString('settings.secret_set', $log);
        self::assertStringNotContainsString($plain, $log);

        self::assertTrue($this->submit($edit, ['ServiceSiteSetting[value]' => '', 'ServiceSiteSetting[clearSecret]' => '1'])->isRedirect());
        self::assertNull($this->stored(K::GOOGLE_CLIENT_SECRET));
        self::assertStringContainsString('settings.secret_cleared', $this->auditLog());
    }

    public function testGoogleKeysFromAdminEnableReviewFormAndRedirect(): void
    {
        $page = (string) $this->http('GET', 'https://' . $this->branch . '/reviews/')->getContent();
        self::assertStringNotContainsString('/connect/google', $page, 'без ключів — кнопки нема');

        $this->set([K::GOOGLE_SIGN_IN_ENABLED => '1', K::GOOGLE_CLIENT_ID => self::CLIENT_ID]);
        $this->setSecret(K::GOOGLE_CLIENT_SECRET, 'test-only-client-secret-wxyz');

        $page = (string) $this->http('GET', 'https://' . $this->branch . '/reviews/')->getContent();
        self::assertStringContainsString('id="add-review"', $page, 'форма відгуку з’явилась');
        self::assertStringContainsString('/connect/google', $page);

        $connect = $this->http('GET', 'https://' . $this->branch . '/connect/google?return=/reviews/');
        $location = (string) $connect->headers->get('Location');
        self::assertTrue($connect->isRedirection());
        self::assertStringStartsWith('https://accounts.google.com/', $location, 'до редиректу на Google (без входу)');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame(self::CLIENT_ID, $query['client_id'] ?? null, 'Client ID — з налаштувань сайту');
        self::assertSame('https://' . $this->branch . '/connect/google/check', $query['redirect_uri'] ?? null, 'redirect URI — на хості міста');
        self::assertStringNotContainsString('test-only-client-secret', $location);

        // «Перевірити налаштування» (discovery/JWKS — фейкові, tests/Support/GoogleProbeMock)
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $index = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting?site=' . $this->siteId)->getContent();
        self::assertStringContainsString('data-google-source="settings"', $index);
        self::assertSame(1, preg_match('/<form method="post" action="([^"]+)" class="d-inline" id="google-check">\s*<input type="hidden" name="token" value="([^"]+)"/', $index, $m));
        $check = $this->http('POST', 'https://' . $this->adminHost . html_entity_decode($m[1]), ['token' => html_entity_decode($m[2])]);
        self::assertTrue($check->isRedirect());
        $after = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting?site=' . $this->siteId)->getContent();
        self::assertStringContainsString('id="google-check-result"', $after);
        self::assertStringContainsString('ключів підпису 2', $after);
        self::assertStringNotContainsString('text-danger">✗', $after, 'усі перевірки пройдено');
    }

    public function testAdminTitleFallsBackWhenSeveralSitesAndNoHostMatch(): void
    {
        $em = $this->em();
        $site = $em->find(ServiceSite::class, $this->siteId);
        self::assertNotNull($site);
        $site->setApexDomain('other-' . $this->apex);
        $em->flush();
        $count = $em->getRepository(ServiceSite::class)->count([]);

        $login = (string) $this->http('GET', 'https://' . $this->adminHost . '/login')->getContent();
        if ($count > 1) {
            self::assertStringContainsString('<h1 style="font-size:1.4rem">Сервісні сайти</h1>', $login, 'кілька сайтів, хост адмінки нічий — як було');
        } else {
            self::assertStringContainsString('<h1 style="font-size:1.4rem">CI Settings Site</h1>', $login, 'сайт один — його назва');
        }

        $this->set([K::ADMIN_TITLE => 'Своя назва']);
        $site = $this->em()->find(ServiceSite::class, $this->siteId);
        self::assertNotNull($site);
        $site->setApexDomain($this->apex);
        $this->em()->flush();
        self::assertStringContainsString('<title>Вхід - Своя назва</title>', (string) $this->http('GET', 'https://' . $this->adminHost . '/login')->getContent());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    /** @param array<string, string> $values */
    private function set(array $values): void
    {
        $settings = static::getContainer()->get(SiteSettings::class);
        $site = $this->em()->find(ServiceSite::class, $this->siteId);
        self::assertNotNull($site);
        foreach ($values as $code => $value) {
            $settings->set($site, $code, $value);
        }
        $this->em()->clear();
    }

    private function setSecret(string $code, string $plain): void
    {
        $this->set([$code => $plain]);
    }

    private function stored(string $code): ?string
    {
        $this->em()->clear();
        $row = $this->em()->getRepository(ServiceSiteSetting::class)->findOneBy(['site' => $this->siteId, 'code' => $code]);

        return $row?->getValue();
    }

    private function editUrl(string $code): string
    {
        $html = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting?site=' . $this->siteId)->getContent();
        self::assertSame(1, preg_match('/<a href="([^"]+)" data-setting-edit="' . preg_quote($code, '/') . '"/', $html, $m), 'посилання на редагування ' . $code);

        return 'https://' . $this->adminHost . html_entity_decode($m[1]);
    }

    /** @param array<string, string> $values */
    private function submit(string $editUrl, array $values): \Symfony\Component\HttpFoundation\Response
    {
        $form = (string) $this->http('GET', $editUrl)->getContent();
        $fields = array_replace(self::formFields($form, 'ServiceSiteSetting'), $values);

        return $this->http('POST', $editUrl, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]]);
    }

    private function auditLog(): string
    {
        $file = static::getContainer()->getParameter('kernel.logs_dir') . '/security_audit.log';

        return is_file($file) ? (string) file_get_contents($file) : '';
    }
}
