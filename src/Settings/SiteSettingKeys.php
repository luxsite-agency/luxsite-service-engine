<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

/**
 * Реєстр налаштувань сайту в адмінці (модель shop-engine SettingKeys, 21.10.2026): код → тип поля й група. Значення —
 * рядок на сайт (ServiceSiteSetting); ключі поза реєстром не зберігаються. Підписи й підказки — домен admin
 * (setting.key.<код>.label/help, setting.group.<група>), uk/ru. Новий ключ — разом з перекладами в translations/admin.*.
 *
 * Що тут, а що ні: тут — те, що клієнт вводить сам (ключі Google, id лічильників, коди верифікації, свої HTML-коди).
 * Лічильник міста (ServiceCity.gtmId, «Міста сервісів») лишається на місті й має пріоритет над GTM сайту (SiteTags).
 * CRM, віджет відгуків, тема — у паку й .env.local (доступи й мапінг полів, зміна ламає прийом заявок).
 */
final class SiteSettingKeys
{
    public const string TYPE_TEXT = 'text';
    public const string TYPE_BOOL = 'bool';
    public const string TYPE_HTML = 'html';
    /** Секрет: у БД — конверт SecretBox, в адмінці — маска й заміна без показу старого; SiteSettings::get() віддає маску. */
    public const string TYPE_SECRET = 'secret';
    /** Так / ні / не задано: порожньо — діє параметр паку (не «ні»), '1' / '0' — перекриття з адмінки. */
    public const string TYPE_BOOL_DEFAULT = 'bool_default';
    /** Кілька рядків тексту (не HTML): у формі — textarea, на сайт не виводиться сирим. */
    public const string TYPE_LINES = 'lines';

    public const string ADMIN_TITLE = 'site.admin_title';
    public const string GOOGLE_SIGN_IN_ENABLED = 'google.sign_in_enabled';
    public const string GOOGLE_CLIENT_ID = 'google.client_id';
    public const string GOOGLE_CLIENT_SECRET = 'google.client_secret';
    public const string GTM_CONTAINER_ID = 'analytics.gtm_container_id';
    public const string GA4_MEASUREMENT_ID = 'analytics.ga4_measurement_id';
    public const string GOOGLE_ADS_ID = 'analytics.google_ads_id';
    public const string GOOGLE_ADS_LEAD_LABEL = 'analytics.google_ads_lead_label';
    public const string CLARITY_ID = 'analytics.clarity_id';
    public const string FB_PIXEL_ID = 'analytics.fb_pixel_id';
    public const string CONSENT_BANNER = 'analytics.consent_banner';
    public const string GOOGLE_SITE_VERIFICATION = 'seo.google_site_verification';
    public const string BING_SITE_VERIFICATION = 'seo.bing_site_verification';
    /** hreflang і для одномовного сайту (з 08.11.2026); не задано — app.hreflang_single паку */
    public const string HREFLANG_SINGLE = 'seo.hreflang_single';
    /** коди hreflang за мовою сайту «en: en-US en-GB» (рядок на мову); не задано — app.locale_codes[…].hreflang паку → рушій */
    public const string HREFLANG_CODES = 'seo.hreflang_codes';
    public const string HTML_HEAD = 'html.head';
    public const string HTML_BODY_START = 'html.body_start';
    public const string HTML_BODY_END = 'html.body_end';

    /** Порядок груп у списку налаштувань і в підписах «група → поле». */
    public const array GROUPS = ['google', 'analytics', 'seo', 'html', 'site'];

    /** @var array<string, array{type: string, group: string}> */
    private const array KEYS = [
        self::GOOGLE_SIGN_IN_ENABLED => ['type' => self::TYPE_BOOL, 'group' => 'google'],
        self::GOOGLE_CLIENT_ID => ['type' => self::TYPE_TEXT, 'group' => 'google'],
        self::GOOGLE_CLIENT_SECRET => ['type' => self::TYPE_SECRET, 'group' => 'google'],
        self::GTM_CONTAINER_ID => ['type' => self::TYPE_TEXT, 'group' => 'analytics'],
        self::GA4_MEASUREMENT_ID => ['type' => self::TYPE_TEXT, 'group' => 'analytics'],
        self::GOOGLE_ADS_ID => ['type' => self::TYPE_TEXT, 'group' => 'analytics'],
        self::GOOGLE_ADS_LEAD_LABEL => ['type' => self::TYPE_TEXT, 'group' => 'analytics'],
        self::CLARITY_ID => ['type' => self::TYPE_TEXT, 'group' => 'analytics'],
        self::FB_PIXEL_ID => ['type' => self::TYPE_TEXT, 'group' => 'analytics'],
        self::CONSENT_BANNER => ['type' => self::TYPE_BOOL, 'group' => 'analytics'],
        self::GOOGLE_SITE_VERIFICATION => ['type' => self::TYPE_TEXT, 'group' => 'seo'],
        self::BING_SITE_VERIFICATION => ['type' => self::TYPE_TEXT, 'group' => 'seo'],
        self::HREFLANG_SINGLE => ['type' => self::TYPE_BOOL_DEFAULT, 'group' => 'seo'],
        self::HREFLANG_CODES => ['type' => self::TYPE_LINES, 'group' => 'seo'],
        self::HTML_HEAD => ['type' => self::TYPE_HTML, 'group' => 'html'],
        self::HTML_BODY_START => ['type' => self::TYPE_HTML, 'group' => 'html'],
        self::HTML_BODY_END => ['type' => self::TYPE_HTML, 'group' => 'html'],
        self::ADMIN_TITLE => ['type' => self::TYPE_TEXT, 'group' => 'site'],
    ];

    /**
     * Формати: у поле id не має потрапити код чи чужий ключ. Невалідне значення адмінка не зберігає (помилка біля поля),
     * а якщо воно все ж у БД (правило змінилось) — сайт його не виводить, у списку бейдж «невірний формат».
     */
    private const array PATTERNS = [
        // OAuth client id Google: «<номер проєкту>-<рядок>.apps.googleusercontent.com»; secret чи API key сюди не пройде
        self::GOOGLE_CLIENT_ID => '/^\d{6,}-[a-z0-9]{8,}\.apps\.googleusercontent\.com$/',
        self::GTM_CONTAINER_ID => '/^GTM-[A-Z0-9]{4,12}$/',
        self::GA4_MEASUREMENT_ID => '/^G-[A-Z0-9]{6,14}$/',
        self::GOOGLE_ADS_ID => '/^AW-[0-9]{6,14}$/',
        self::GOOGLE_ADS_LEAD_LABEL => '/^[A-Za-z0-9_-]{6,64}$/',
        self::CLARITY_ID => '/^[a-z0-9]{6,20}$/',
        self::FB_PIXEL_ID => '/^[0-9]{6,20}$/',
        self::GOOGLE_SITE_VERIFICATION => '/^[A-Za-z0-9_=-]{8,128}$/',
        self::BING_SITE_VERIFICATION => '/^[A-Za-z0-9_=-]{8,128}$/',
        self::ADMIN_TITLE => '/^[^\r\n<>]{1,64}$/u',
        self::HREFLANG_SINGLE => '/^[01]$/',
    ];

    /**
     * Код hreflang — тег мови BCP 47 у звичному для hreflang вигляді: мова (en), +письмо (zh-Hant), +регіон (en-US, es-419).
     * x-default сюди не проходить: його рушій ставить сам (перша мова сайту).
     */
    private const string HREFLANG_TAG = '/^[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|[0-9]{3}))?$/';

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::KEYS);
    }

    public static function isKnown(string $code): bool
    {
        return isset(self::KEYS[$code]);
    }

    public static function assertKnown(string $code): string
    {
        if (!self::isKnown($code)) {
            throw new \InvalidArgumentException(\sprintf('Невідомий ключ налаштування «%s»', $code));
        }

        return $code;
    }

    public static function type(string $code): string
    {
        return self::KEYS[$code]['type'] ?? self::TYPE_TEXT;
    }

    public static function group(string $code): string
    {
        return self::KEYS[$code]['group'] ?? '';
    }

    public static function isSecret(string $code): bool
    {
        return self::TYPE_SECRET === self::type($code);
    }

    /** Ключ перекладу підпису (домен admin). */
    public static function labelKey(string $code): string
    {
        return 'setting.key.' . $code . '.label';
    }

    /** Ключ перекладу підказки поля (домен admin). */
    public static function helpKey(string $code): string
    {
        return 'setting.key.' . $code . '.help';
    }

    /** Ключ перекладу назви групи (домен admin). */
    public static function groupKey(string $code): string
    {
        return 'setting.group.' . self::group($code);
    }

    /** true, якщо значення порожнє, для ключа нема формату або значення йому відповідає. */
    public static function isValidValue(string $code, ?string $value): bool
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return true;
        }
        if (self::HREFLANG_CODES === $code) {
            return null !== self::parseHreflangCodes($value);
        }
        if (!isset(self::PATTERNS[$code])) {
            return true;
        }

        return 1 === preg_match(self::PATTERNS[$code], $value);
    }

    /** Значення для виводу або null, якщо порожнє чи невалідне. */
    public static function validValue(string $code, ?string $value): ?string
    {
        $value = trim((string) $value);
        if ('' === $value || !self::isValidValue($code, $value)) {
            return null;
        }

        return $value;
    }

    /**
     * «en: en-US en-GB» (рядки або «;») → [мова => [коди]]; null — формат не той: рядок без «мова:», код не BCP 47
     * (або x-default), мова двічі, порожній список кодів. Регістр нормалізується (en-us → en-US).
     *
     * @return array<string, list<string>>|null
     */
    public static function parseHreflangCodes(?string $value): ?array
    {
        $out = [];
        foreach (preg_split('/[\r\n;]+/', (string) $value) ?: [] as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            if (1 !== preg_match('/^([A-Za-z]{2,3})\s*:\s*(.+)$/', $line, $m)) {
                return null;
            }
            $lang = strtolower($m[1]);
            if (isset($out[$lang])) {
                return null;
            }
            $codes = [];
            foreach (preg_split('/[\s,]+/', trim($m[2]), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
                $tag = self::canonicalTag($tag);
                if (1 !== preg_match(self::HREFLANG_TAG, $tag)) {
                    return null;
                }
                $codes[] = $tag;
            }
            if ([] === $codes) {
                return null;
            }
            $out[$lang] = array_values(array_unique($codes));
        }

        return [] === $out ? null : $out;
    }

    /** en-us → en-US, zh-hant-tw → zh-Hant-TW, es-419 як є. */
    private static function canonicalTag(string $tag): string
    {
        $parts = explode('-', $tag);
        foreach ($parts as $i => $p) {
            $parts[$i] = 0 === $i ? strtolower($p) : (4 === \strlen($p) ? ucfirst(strtolower($p)) : strtoupper($p));
        }

        return implode('-', $parts);
    }

    /**
     * Що зберегти з того, що вставили: у поле id можна вставити цілий код від Google, Meta чи Microsoft — ідентифікатор
     * дістається звідти (як «Лічильник аналітики» міста). Не знайшлось — вводене як є, щоб валідація показала помилку
     * біля поля, а не порожнечу. HTML і секрети не чіпаються.
     */
    public static function normalize(string $code, ?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }
        $type = self::type($code);
        if (self::TYPE_HTML === $type || self::TYPE_SECRET === $type) {
            return '' === trim($raw) ? null : $raw;
        }
        $value = trim($raw);
        if ('' === $value) {
            return null;
        }
        if (self::TYPE_BOOL_DEFAULT === $type) {
            $b = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);

            return null === $b ? $value : ($b ? '1' : '0');
        }
        if (self::HREFLANG_CODES === $code) {
            $parsed = self::parseHreflangCodes($value);
            if (null === $parsed) {
                return $value;
            }

            return implode("\n", array_map(static fn (string $l, array $c): string => $l . ': ' . implode(' ', $c), array_keys($parsed), $parsed));
        }
        $extract = static fn (string $pattern, int $group = 1): ?string => 1 === preg_match($pattern, $value, $m) ? $m[$group] : null;

        return match ($code) {
            self::GTM_CONTAINER_ID => strtoupper($extract('/(GTM-[A-Z0-9]{4,12})/i') ?? $value),
            self::GA4_MEASUREMENT_ID => strtoupper($extract('/(?<![A-Z0-9-])(G-[A-Z0-9]{6,14})/i') ?? $value),
            self::GOOGLE_ADS_ID => strtoupper($extract('/(AW-[0-9]{6,14})/i') ?? $value),
            // send_to: 'AW-123456789/AbC-dEf_123' → лише мітка після «/»
            self::GOOGLE_ADS_LEAD_LABEL => $extract('#AW-[0-9]{6,14}/([A-Za-z0-9_-]{6,64})#i') ?? $value,
            // код Clarity: …(window, document, "clarity", "script", "abcdefghij")
            self::CLARITY_ID => strtolower($extract('/["\']script["\']\s*,\s*["\']([a-z0-9]{6,20})["\']/i') ?? $extract('#clarity\.ms/tag/([a-z0-9]{6,20})#i') ?? $value),
            // код пікселя: fbq('init', '1234567890') або …/tr?id=1234567890
            self::FB_PIXEL_ID => $extract('/init["\']?\s*,\s*["\']([0-9]{6,20})["\']/') ?? $extract('/[?&]id=([0-9]{6,20})/') ?? $value,
            // <meta name="google-site-verification" content="…"> → значення content
            self::GOOGLE_SITE_VERIFICATION, self::BING_SITE_VERIFICATION => $extract('/content\s*=\s*["\']([^"\']+)["\']/i') ?? $value,
            default => $value,
        };
    }
}
