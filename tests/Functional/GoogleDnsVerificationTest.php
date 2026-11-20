<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Entity\ServiceSiteSetting;
use App\Entity\User;
use App\Settings\GoogleDnsVerification;
use App\Settings\SecretBox;
use App\Settings\SiteSettingKeys as K;
use App\Settings\SiteSettings;
use App\Tests\Support\CloudflareFake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Підтвердження в Google Search Console ресурсом «Домен» через DNS TXT (власник 20.11.2026): значення вводиться на
 * сторінці бренду, рушій ставить TXT на апекс бренду в Cloudflare (фейковий API — tests/Support/CloudflareFake) і
 * прибирає лише свої записи. Файлів підтвердження на сайтах нема.
 */
final class GoogleDnsVerificationTest extends HttpKernelTestCase
{
    private const string VALUE = 'abcDEF_123-xyzxyzxyzxyz';
    private const string TXT = 'google-site-verification=' . self::VALUE;
    private const string TOKEN = 'cf-test-token-NOT-REAL-7f3a9c41';

    private string $apexA;
    private string $branchA;
    private string $apexB;
    private string $adminHost;
    private int $siteA;
    private int $siteB;
    private string $adminEmail;
    private string $moderatorEmail;
    private string $password;

    protected function setUp(): void
    {
        $tag = bin2hex(random_bytes(3));
        $this->apexA = 'ci-dns-a-' . $tag . '.test';
        $this->branchA = 'kyiv.' . $this->apexA;
        $this->apexB = 'ci-dns-b-' . $tag . '.test';
        $this->adminHost = 'admin.' . $this->apexA;
        $this->boot(self::TOKEN);

        $em = $this->em();
        $a = (new ServiceSite())->setSlug('ci-dns-a-' . $tag)->setName('CI DNS A')->setApexDomain($this->apexA);
        $b = (new ServiceSite())->setSlug('ci-dns-b-' . $tag)->setName('CI DNS B')->setApexDomain($this->apexB);
        $em->persist($a);
        $em->persist($b);
        $em->persist((new ServiceCity())->setSite($a)->setHost($this->apexA)->setSlug('main')->setIsApex(true)->setNameNomUk('Головне'));
        $em->persist((new ServiceCity())->setSite($a)->setHost($this->branchA)->setSlug('kyiv')->setNameNomUk('Київ'));
        $em->persist((new ServiceCity())->setSite($b)->setHost($this->apexB)->setSlug('main')->setIsApex(true)->setNameNomUk('Головне'));
        $this->password = bin2hex(random_bytes(8));
        $this->adminEmail = 'ci-dns-' . $tag . '-admin@example.test';
        $this->moderatorEmail = 'ci-dns-' . $tag . '-moderator@example.test';
        foreach ([$this->adminEmail => 'ROLE_ADMIN', $this->moderatorEmail => 'ROLE_MODERATOR'] as $email => $role) {
            $user = (new User())->setEmail($email)->setRoles([$role]);
            $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $this->password));
            $em->persist($user);
        }
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
        foreach ([$this->adminEmail, $this->moderatorEmail] as $email) {
            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (null !== $user) {
                $em->remove($user);
            }
        }
        $em->flush();
        parent::tearDown();
    }

    public function testSaveCreatesTxtOnApexInCloudflareAndShowsStatus(): void
    {
        $this->cf()->addZone($this->apexA);
        $this->login($this->adminHost, $this->adminEmail, $this->password);

        self::assertTrue($this->submit($this->editUrl($this->siteA), self::VALUE)->isRedirect(), 'збережено');
        self::assertSame(self::VALUE, $this->stored($this->siteA));

        $posts = $this->cf()->requests('POST');
        self::assertCount(1, $posts, 'один запис на одне значення');
        self::assertSame('/client/v4/zones/' . $this->cf()->zones[$this->apexA] . '/dns_records', $posts[0]['path']);
        self::assertSame('TXT', $posts[0]['body']['type'] ?? null);
        self::assertSame($this->apexA, $posts[0]['body']['name'] ?? null, 'апекс (@), не міста');
        self::assertSame(self::TXT, $posts[0]['body']['content'] ?? null);
        self::assertSame('Bearer ' . self::TOKEN, $posts[0]['auth']);
        self::assertSame([self::TXT], $this->cf()->txt($this->apexA));

        $page = $this->index($this->siteA);
        self::assertSame(1, preg_match('/<ul[^>]*id="dns-sync-result"[^>]*>.*?data-dns-state="ok"/s', $page), 'результат збереження — flash');
        self::assertStringContainsString('data-dns-domain="' . $this->apexA . '" data-dns-state="ok"', $page, 'стан на сторінці бренду — «запис стоїть»');
        self::assertStringNotContainsString(self::TOKEN, $page);
        self::assertStringContainsString('ім&#039;я @', $page, 'апостроф у підписі колонки — один');
        self::assertStringNotContainsString('&#039;&#039;', $page);

        // «Перевірити» — DoH 1.1.1.1
        $check = $this->dnsCheck($page);
        self::assertTrue($check->isRedirect());
        $after = $this->index($this->siteA);
        self::assertSame(1, preg_match('/id="dns-check-result".*?data-dns-visible="1"/s', $after), 'видно в DNS');
        $doh = array_values(array_filter($this->cf()->requests('GET'), static fn (array $r): bool => '/dns-query' === $r['path']));
        self::assertNotEmpty($doh);
        self::assertSame($this->apexA, $doh[0]['query']['name'] ?? null);
        self::assertNull($doh[0]['auth'], 'токен Cloudflare не йде в DoH');
    }

    public function testPastedTxtLineSavesCleanValueWithoutDuplicate(): void
    {
        $this->cf()->addZone($this->apexA);
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $edit = $this->editUrl($this->siteA);

        self::assertTrue($this->submit($edit, self::VALUE)->isRedirect());
        self::assertTrue($this->submit($edit, self::TXT)->isRedirect(), 'вставили рядок цілком');
        self::assertSame(self::VALUE, $this->stored($this->siteA), 'у БД — лише значення');
        self::assertTrue($this->submit($edit, '"' . self::TXT . '"')->isRedirect(), 'і в лапках');
        self::assertSame(self::VALUE, $this->stored($this->siteA));
        self::assertCount(1, $this->cf()->requests('POST'), 'повторне збереження не створює другий запис');
        self::assertSame([self::TXT], $this->cf()->txt($this->apexA));
    }

    public function testRemovingValueDeletesOnlyOwnRecord(): void
    {
        $this->cf()->addZone($this->apexA);
        $this->cf()->addRecord($this->apexA, $this->apexA, 'v=spf1 include:_spf.example.test ~all');
        $this->cf()->addRecord($this->apexA, $this->apexA, '"google-site-verification=other-foreign-token-1234"');
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $edit = $this->editUrl($this->siteA);

        self::assertTrue($this->submit($edit, self::VALUE)->isRedirect());
        $own = array_values(array_filter($this->cf()->records[$this->cf()->zones[$this->apexA]], static fn (array $r): bool => self::TXT === $r['content']));
        self::assertCount(1, $own);

        self::assertTrue($this->submit($edit, '')->isRedirect(), 'прибрали значення');
        self::assertNull($this->stored($this->siteA));
        $deletes = $this->cf()->requests('DELETE');
        self::assertCount(1, $deletes, 'лише свій запис');
        self::assertStringEndsWith('/dns_records/' . $own[0]['id'], $deletes[0]['path']);
        self::assertSame(['v=spf1 include:_spf.example.test ~all', '"google-site-verification=other-foreign-token-1234"'], $this->cf()->txt($this->apexA), 'SPF і чужий google-site-verification — на місці');
    }

    public function testInvalidValueIsRejectedWithoutApiCalls(): void
    {
        $this->cf()->addZone($this->apexA);
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $edit = $this->editUrl($this->siteA);

        $six = implode(' ', array_map(static fn (int $i): string => self::VALUE . $i, range(1, 6)));
        foreach (['<script>alert(1)</script>', 'abcde', $six] as $bad) {
            self::assertSame(422, $this->submit($edit, $bad)->getStatusCode(), 'помилка біля поля: ' . $bad);
            self::assertNull($this->stored($this->siteA), 'у БД нічого: ' . $bad);
        }
        self::assertSame([], $this->cf()->requests, 'у Cloudflare нічого');
    }

    public function testZoneNotInOurCloudflareSavesAndShowsManualLine(): void
    {
        $this->login($this->adminHost, $this->adminEmail, $this->password);

        self::assertTrue($this->submit($this->editUrl($this->siteB), self::VALUE)->isRedirect(), 'збережено');
        self::assertSame(self::VALUE, $this->stored($this->siteB));
        self::assertSame([], $this->cf()->requests('POST'), 'жодного POST');

        $page = $this->index($this->siteB);
        self::assertStringContainsString('data-dns-domain="' . $this->apexB . '" data-dns-state="no_zone"', $page);
        self::assertStringContainsString(self::TXT, $page, 'точний рядок для ручного додавання');
    }

    public function testWithoutTokenSavesAndMakesNoHttpRequests(): void
    {
        $this->boot('');
        $this->cf()->addZone($this->apexA);
        $this->login($this->adminHost, $this->adminEmail, $this->password);

        self::assertTrue($this->submit($this->editUrl($this->siteA), self::VALUE)->isRedirect());
        self::assertSame(self::VALUE, $this->stored($this->siteA));
        $page = $this->index($this->siteA);
        self::assertStringContainsString('data-dns-domain="' . $this->apexA . '" data-dns-state="disabled"', $page);
        self::assertStringContainsString(self::TXT, $page);
        self::assertSame([], $this->cf()->requests, 'жодного HTTP-запиту');
    }

    public function testApiErrorKeepsSettingAndNeverShowsToken(): void
    {
        $this->cf()->addZone($this->apexA);
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $edit = $this->editUrl($this->siteA);

        foreach ([403, 500] as $code) {
            $this->cf()->failWith = $code;
            $value = self::VALUE . $code;
            self::assertTrue($this->submit($edit, $value)->isRedirect(), 'збереження не ламається: ' . $code);
            self::assertSame($value, $this->stored($this->siteA));
            $page = $this->index($this->siteA);
            self::assertSame(1, preg_match('/id="dns-sync-result".*?data-dns-state="error"[^>]*>[^<]*' . $code . '/s', $page), 'статус з кодом ' . $code);
            self::assertStringNotContainsString(self::TOKEN, $page);
        }
        foreach (glob(static::getContainer()->getParameter('kernel.logs_dir') . '/*.log') ?: [] as $log) {
            self::assertStringNotContainsString(self::TOKEN, (string) file_get_contents($log), basename($log));
        }
    }

    /** Рев'ю 1: «свій» запис — лише TXT з міткою на імені апекса; фільтр name= Cloudflare може проігнорувати. */
    public function testMarkedRecordOnOtherNameIsNeitherDeletedNorCounted(): void
    {
        $this->cf()->addZone($this->apexA);
        $this->cf()->ignoreNameFilter = true;
        $sub = 'shop.' . $this->apexA;
        $sameValue = $this->cf()->addRecord($this->apexA, $sub, self::TXT, GoogleDnsVerification::MARKER);
        $oldValue = $this->cf()->addRecord($this->apexA, $sub, 'google-site-verification=old-value-on-subdomain-1', GoogleDnsVerification::MARKER);
        $cname = $this->cf()->addRecord($this->apexA, $this->apexA, 'google-site-verification=old-value-not-txt-12345', GoogleDnsVerification::MARKER, 'SPF');
        $this->login($this->adminHost, $this->adminEmail, $this->password);

        self::assertTrue($this->submit($this->editUrl($this->siteA), self::VALUE)->isRedirect());
        self::assertSame([], $this->cf()->requests('DELETE'), 'мічені записи на іншому імені чи іншого типу не видаляються');
        $posts = $this->cf()->requests('POST');
        self::assertCount(1, $posts, 'запис на піддомені не рахується як «стоїть» для апекса');
        self::assertSame($this->apexA, $posts[0]['body']['name'] ?? null);
        $ids = array_column($this->cf()->records[$this->cf()->zones[$this->apexA]], 'id');
        foreach ([$sameValue, $oldValue, $cname] as $id) {
            self::assertContains($id, $ids);
        }
    }

    /** Рев'ю 3: спершу POST нового, потім DELETE старого — збій POST лишає старий запис. */
    public function testReplaceKeepsOldRecordWhenPostFails(): void
    {
        $this->cf()->addZone($this->apexA);
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $edit = $this->editUrl($this->siteA);
        self::assertTrue($this->submit($edit, self::VALUE)->isRedirect());

        foreach (['429' => 429, 'timeout' => 'timeout', 'nonjson' => 'nonjson'] as $label => $fail) {
            $this->cf()->failOn = ['POST' => $fail];
            $new = 'replacement-value-' . $label . '-xxxxxx';
            self::assertTrue($this->submit($edit, $new)->isRedirect(), 'збережено: ' . $label);
            self::assertSame($new, $this->stored($this->siteA));
            self::assertSame([], $this->cf()->requests('DELETE'), 'старий не видалено: ' . $label);
            self::assertContains(self::TXT, $this->cf()->txt($this->apexA), 'старе значення в DNS: ' . $label);
            $page = $this->index($this->siteA);
            self::assertSame(1, preg_match('/id="dns-sync-result".*?data-dns-state="error"[^>]*>([^<]*)</s', $page, $m), 'стан — помилка: ' . $label);
            self::assertStringContainsString('створено 0, видалено 0', html_entity_decode($m[1]), 'лічильники: ' . $label);
            if (429 === $fail) {
                self::assertStringContainsString('429', $m[1]);
            }
            if ('nonjson' === $fail) {
                self::assertStringContainsString('502', $m[1]);
            }
        }
    }

    public function testParentZoneIsUsedForBrandOnSubdomain(): void
    {
        $brand = 'brand.' . $this->apexB;
        $em = $this->em();
        $site = $em->find(ServiceSite::class, $this->siteB);
        self::assertNotNull($site);
        $site->setApexDomain($brand);
        $em->flush();
        $em->clear();
        $this->cf()->addZone($this->apexB);
        $this->login($this->adminHost, $this->adminEmail, $this->password);

        self::assertTrue($this->submit($this->editUrl($this->siteB), self::VALUE)->isRedirect());
        $posts = $this->cf()->requests('POST');
        self::assertCount(1, $posts);
        self::assertSame('/client/v4/zones/' . $this->cf()->zones[$this->apexB] . '/dns_records', $posts[0]['path']);
        self::assertSame($brand, $posts[0]['body']['name'] ?? null, 'запис на імені бренду в батьківській зоні');
    }

    public function testInactiveZoneIsReportedAndNotWritten(): void
    {
        $this->cf()->addZone($this->apexA, 'pending');
        $this->login($this->adminHost, $this->adminEmail, $this->password);

        self::assertTrue($this->submit($this->editUrl($this->siteA), self::VALUE)->isRedirect());
        self::assertSame([], $this->cf()->requests('POST'));
        $page = $this->index($this->siteA);
        self::assertSame(1, preg_match('/id="dns-sync-result".*?data-dns-state="zone_inactive"[^>]*>[^<]*pending/s', $page), 'зона є, але не активна — так і сказано');
    }

    /** Рев'ю 6: список налаштувань не ходить у Cloudflare; стан — з останньої синхронізації або за кнопкою. */
    public function testSettingsPageDoesNotCallCloudflare(): void
    {
        $this->cf()->addZone($this->apexA);
        $this->setValue($this->siteA, self::VALUE); // мимо адмінки — стану в кеші нема
        $this->cf()->failOn = ['GET' => 'timeout'];
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $before = \count($this->cf()->requests);

        $page = $this->index($this->siteA);
        self::assertSame($before, \count($this->cf()->requests), 'відкриття сторінки — без запитів до Cloudflare');
        self::assertStringContainsString('data-dns-domain="' . $this->apexA . '" data-dns-state="unknown"', $page);

        $this->cf()->failOn = [];
        self::assertSame(1, preg_match('/<form method="post" action="([^"]+)" class="d-inline" id="dns-status">\s*<input type="hidden" name="token" value="([^"]+)"/', $page, $m), 'кнопка «Стан у Cloudflare»');
        self::assertTrue($this->http('POST', $this->absolute(html_entity_decode($m[1])), ['token' => html_entity_decode($m[2])])->isRedirect());
        self::assertSame([], $this->cf()->requests('POST'), 'кнопка лише читає');
        self::assertStringContainsString('data-dns-domain="' . $this->apexA . '" data-dns-state="pending"', $this->index($this->siteA), 'запису ще нема — так і видно');
    }

    public function testModeratorCannotReachDnsActions(): void
    {
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $page = $this->index($this->siteA);
        self::assertSame(1, preg_match('/action="([^"]+)" class="d-inline" id="dns-check">\s*<input type="hidden" name="token" value="([^"]+)"/', $page, $check));
        self::assertSame(1, preg_match('/action="([^"]+)" class="d-inline" id="dns-status">/', $page, $status));
        $this->http('GET', 'https://' . $this->adminHost . '/logout');
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);

        self::assertSame(403, $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting?site=' . $this->siteA)->getStatusCode());
        self::assertSame(403, $this->http('POST', $this->absolute(html_entity_decode($check[1])), ['token' => html_entity_decode($check[2])])->getStatusCode());
        self::assertSame(403, $this->http('POST', $this->absolute(html_entity_decode($status[1])), ['token' => 'x'])->getStatusCode());
        self::assertSame([], $this->cf()->requests);
    }

    public function testTokenIsTrimmedTheSameEverywhere(): void
    {
        $this->boot("  " . self::TOKEN . "\n");
        $this->cf()->addZone($this->apexA);
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        self::assertTrue($this->submit($this->editUrl($this->siteA), self::VALUE)->isRedirect());
        self::assertSame('Bearer ' . self::TOKEN, $this->cf()->requests('POST')[0]['auth'] ?? null);

        $this->boot(" \n ");
        $this->cf()->addZone($this->apexA);
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        self::assertTrue($this->submit($this->editUrl($this->siteA), self::VALUE . 'x')->isRedirect());
        self::assertSame([], $this->cf()->requests, 'токен із пробілів = нема токена');
        self::assertStringContainsString('data-dns-state="disabled"', $this->index($this->siteA));
    }

    /** Рев'ю 4: рядки ключів, яких більше нема в реєстрі (файли підтвердження з #66), не показуються сирими кодами. */
    public function testRemovedKeysAreNotListed(): void
    {
        $db = $this->em()->getConnection();
        foreach (['seo.google_verification_files' => 'google111aaa222bbb.html', 'seo.bing_site_auth' => '0123456789ABCDEF0123456789ABCDEF'] as $code => $value) {
            $db->insert('service_site_setting', ['site_id' => $this->siteA, 'code' => $code, 'value' => $value, 'updated_at' => '2026-11-20 12:00:00']);
        }
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $page = $this->index($this->siteA);
        self::assertStringNotContainsString('seo.google_verification_files', $page);
        self::assertStringNotContainsString('seo.bing_site_auth', $page);
        self::assertStringContainsString('data-setting-edit="' . K::GOOGLE_DNS_VERIFICATION . '"', $page);
    }

    public function testVerificationFilesAreGone(): void
    {
        self::assertSame([], glob(\dirname(__DIR__, 2) . '/public/google*.html') ?: [], 'жодного файла Google у public рушія');
        self::assertFileDoesNotExist(\dirname(__DIR__, 2) . '/public/BingSiteAuth.xml');
        foreach ([$this->apexA, $this->branchA, $this->apexB] as $host) {
            foreach (['/google3cc17ecbb1f93a59.html', '/google111aaa.html', '/google111aaa222bbb.html', '/BingSiteAuth.xml'] as $path) {
                self::assertSame(404, $this->http('GET', 'https://' . $host . $path)->getStatusCode(), $host . $path);
            }
        }
    }

    public function testPagesUnchanged(): void
    {
        $this->cf()->addZone($this->apexA);
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        self::assertTrue($this->submit($this->editUrl($this->siteA), self::VALUE)->isRedirect());
        $page = (string) $this->http('GET', 'https://' . $this->apexA . '/reviews/')->getContent();
        self::assertStringNotContainsString(self::VALUE, $page, 'TXT живе в DNS, не в сторінці');
        self::assertStringNotContainsString('<!--', $page);
    }

    private function boot(string $token): void
    {
        foreach (['SETTINGS_SECRET_KEY' => SecretBox::generateKey(), 'ADMIN_HOST' => $this->adminHost, 'CLOUDFLARE_DNS_TOKEN' => $token] as $k => $v) {
            $_SERVER[$k] = $_ENV[$k] = $v;
            putenv($k . '=' . $v);
        }
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->cf()->reset();
    }

    private function setValue(int $siteId, string $value): void
    {
        $site = $this->em()->find(ServiceSite::class, $siteId);
        self::assertNotNull($site);
        static::getContainer()->get(SiteSettings::class)->set($site, K::GOOGLE_DNS_VERIFICATION, $value);
        $this->em()->clear();
    }

    private function cf(): CloudflareFake
    {
        return static::getContainer()->get(CloudflareFake::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function stored(int $siteId): ?string
    {
        $this->em()->clear();

        return $this->em()->getRepository(ServiceSiteSetting::class)->findOneBy(['site' => $siteId, 'code' => K::GOOGLE_DNS_VERIFICATION])?->getValue();
    }

    private function index(int $siteId): string
    {
        return (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/site-setting?site=' . $siteId)->getContent();
    }

    private function editUrl(int $siteId): string
    {
        $html = $this->index($siteId);
        self::assertSame(1, preg_match('/<a href="([^"]+)" data-setting-edit="' . preg_quote(K::GOOGLE_DNS_VERIFICATION, '/') . '"/', $html, $m), 'посилання на редагування');

        return $this->absolute(html_entity_decode($m[1]));
    }

    private function dnsCheck(string $page): Response
    {
        self::assertSame(1, preg_match('/<form method="post" action="([^"]+)" class="d-inline" id="dns-check">\s*<input type="hidden" name="token" value="([^"]+)"/', $page, $m), 'кнопка «Перевірити в DNS»');

        return $this->http('POST', $this->absolute(html_entity_decode($m[1])), ['token' => html_entity_decode($m[2])]);
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : 'https://' . $this->adminHost . $url;
    }

    private function submit(string $editUrl, string $value): Response
    {
        $form = (string) $this->http('GET', $editUrl)->getContent();
        $fields = array_replace(self::formFields($form, 'ServiceSiteSetting'), ['ServiceSiteSetting[value]' => $value]);

        return $this->http('POST', $editUrl, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]]);
    }
}
