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
