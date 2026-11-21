<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Settings\SiteSettingKeys as K;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SiteSettingKeysTest extends TestCase
{
    /** @return iterable<string, array{string, string, string|null}> */
    public static function pasted(): iterable
    {
        yield 'GTM: цілий код контейнера' => [K::GTM_CONTAINER_ID, "<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){})(window,document,'script','dataLayer','gtm-ab12cd3');</script>", 'GTM-AB12CD3'];
        yield 'GA4: код gtag' => [K::GA4_MEASUREMENT_ID, '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ9"></script>', 'G-ABC123XYZ9'];
        yield 'GA4: сам id малими' => [K::GA4_MEASUREMENT_ID, ' g-abc123xyz9 ', 'G-ABC123XYZ9'];
        yield 'GA4 не бере GTM-' => [K::GA4_MEASUREMENT_ID, 'GTM-AB12CD3', 'GTM-AB12CD3'];
        yield 'Ads: id із коду' => [K::GOOGLE_ADS_ID, "gtag('config', 'AW-123456789');", 'AW-123456789'];
        yield 'Ads: мітка з send_to' => [K::GOOGLE_ADS_LEAD_LABEL, "gtag('event', 'conversion', {'send_to': 'AW-123456789/AbC-dEf_123'});", 'AbC-dEf_123'];
        yield 'Clarity: цілий код' => [K::CLARITY_ID, '(function(c,l,a,r,i,t,y){})(window, document, "clarity", "script", "Abcde12345");', 'abcde12345'];
        yield 'Pixel: цілий код' => [K::FB_PIXEL_ID, "fbq('init', '1234567890123');\nfbq('track', 'PageView');", '1234567890123'];
        yield 'Pixel: noscript' => [K::FB_PIXEL_ID, '<img src="https://www.facebook.com/tr?id=1234567890123&ev=PageView&noscript=1"/>', '1234567890123'];
        yield 'GSC: мета-тег' => [K::GOOGLE_SITE_VERIFICATION, '<meta name="google-site-verification" content="AbCdEf_123-xyz=" />', 'AbCdEf_123-xyz='];
        yield 'GSC-файли: кілька імен через кому й перенос, дублі' => [K::GOOGLE_VERIFICATION_FILES, " google0123abcd.html,\ngoogle89efab01cd.html  google0123abcd.html ", 'google0123abcd.html google89efab01cd.html'];
        yield 'GSC-файли: повна адреса й вміст файла' => [K::GOOGLE_VERIFICATION_FILES, 'https://a.test/google0123abcd.html google-site-verification: google89efab01cd.html', 'google0123abcd.html google89efab01cd.html'];
        yield 'GSC-файли: шлях з ../ лишається як є (валідація відкине)' => [K::GOOGLE_VERIFICATION_FILES, 'google../x.html', 'google../x.html'];
        yield 'Bing: вміст BingSiteAuth.xml' => [K::BING_SITE_AUTH, "<?xml version=\"1.0\"?>\n<users>\n\t<user>0123456789abcdef0123456789ABCDEF</user>\n</users>", '0123456789ABCDEF0123456789ABCDEF'];
        yield 'порожнє → null' => [K::GTM_CONTAINER_ID, '   ', null];
        yield 'HTML як є' => [K::HTML_HEAD, " <script>x()</script>\n", " <script>x()</script>\n"];
    }

    #[DataProvider('pasted')]
    public function testNormalizeExtractsIdFromPastedCode(string $code, string $raw, ?string $expected): void
    {
        self::assertSame($expected, K::normalize($code, $raw));
    }

    public function testFormats(): void
    {
        self::assertTrue(K::isValidValue(K::GOOGLE_CLIENT_ID, '123456789012-abcdefgh12345678.apps.googleusercontent.com'));
        self::assertFalse(K::isValidValue(K::GOOGLE_CLIENT_ID, 'GOCSPX-not-a-client-id'), 'secret у поле Client ID не пройде');
        self::assertFalse(K::isValidValue(K::GTM_CONTAINER_ID, '<script>alert(1)</script>'));
        self::assertTrue(K::isValidValue(K::GTM_CONTAINER_ID, ''), 'порожньо — валідно (не задано)');
        self::assertFalse(K::isValidValue(K::GA4_MEASUREMENT_ID, "G-ABC123'"));
        self::assertFalse(K::isValidValue(K::FB_PIXEL_ID, '12ab'));
        self::assertFalse(K::isValidValue(K::ADMIN_TITLE, "Назва\nз переносом"));
        // файли підтвердження Google: лише google<hex 8–32>.html, до п'яти
        self::assertTrue(K::isValidValue(K::GOOGLE_VERIFICATION_FILES, 'google3cc17ecbb1f93a59.html'));
        self::assertTrue(K::isValidValue(K::GOOGLE_VERIFICATION_FILES, K::normalize(K::GOOGLE_VERIFICATION_FILES, 'google11111111.html google22222222.html google33333333.html google44444444.html google55555555.html')));
        self::assertFalse(K::isValidValue(K::GOOGLE_VERIFICATION_FILES, K::normalize(K::GOOGLE_VERIFICATION_FILES, 'google11111111.html google22222222.html google33333333.html google44444444.html google55555555.html google66666666.html')), 'шостий — забагато');
        foreach (['google../x.html', 'evil.html', 'google1234567.html', 'google0123ABCD.html', 'google0123abcd.htm', 'google0123abcd.html/x', 'google0123abcd.html<script>', 'google0123abcd.html evil.html'] as $bad) {
            self::assertFalse(K::isValidValue(K::GOOGLE_VERIFICATION_FILES, K::normalize(K::GOOGLE_VERIFICATION_FILES, $bad)), $bad);
        }
        self::assertSame(['google0123abcd.html', 'google89efab01cd.html'], K::googleVerificationFiles('google0123abcd.html google89efab01cd.html'));
        self::assertSame([], K::googleVerificationFiles('google0123abcd.html evil.html'), 'невалідне значення в БД — жодного файла');
        self::assertSame([], K::googleVerificationFiles(null));
        self::assertTrue(K::isValidValue(K::BING_SITE_AUTH, '0123456789ABCDEF0123456789ABCDEF'));
        self::assertFalse(K::isValidValue(K::BING_SITE_AUTH, '<users><user>x</user></users>'));
        self::assertFalse(K::isValidValue(K::ADMIN_TITLE, '<b>HTML</b>'));
        self::assertTrue(K::isValidValue(K::ADMIN_TITLE, 'Назва адмінки'));
        self::assertNull(K::validValue(K::GTM_CONTAINER_ID, 'nonsense'));
        self::assertSame('GTM-AB12CD3', K::validValue(K::GTM_CONTAINER_ID, ' GTM-AB12CD3 '));
    }

    public function testRegistry(): void
    {
        self::assertTrue(K::isSecret(K::GOOGLE_CLIENT_SECRET));
        self::assertFalse(K::isSecret(K::GOOGLE_CLIENT_ID));
        self::assertSame(['google.client_secret'], array_values(array_filter(K::all(), K::isSecret(...))), 'єдиний секрет — Client Secret');
        foreach (K::all() as $code) {
            self::assertContains(K::group($code), K::GROUPS, $code);
        }
        $this->expectException(\InvalidArgumentException::class);
        K::assertKnown('unknown.key');
    }

    /** Кожен ключ має підпис і підказку uk/ru, кожна група — назву (домен admin). */
    public function testTranslationsExistForEveryKey(): void
    {
        foreach (['uk', 'ru'] as $locale) {
            $flat = self::flatten(Yaml::parseFile(\dirname(__DIR__, 2) . '/translations/admin.' . $locale . '.yaml'));
            foreach ($flat as $key => $text) {
                // EasyAdmin виводить підказки полів як HTML, а підписи в списку — через strip_tags: «<head>» зник би
                self::assertStringNotContainsString('<', (string) $text, $locale . ': ' . $key);
            }
            foreach (K::all() as $code) {
                self::assertArrayHasKey(K::labelKey($code), $flat, $locale . ': ' . $code);
                self::assertArrayHasKey(K::helpKey($code), $flat, $locale . ': ' . $code);
                self::assertArrayHasKey(K::groupKey($code), $flat, $locale . ': ' . $code);
            }
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $out += self::flatten($value, $prefix . $key . '.');
            } else {
                $out[$prefix . $key] = $value;
            }
        }

        return $out;
    }
}
