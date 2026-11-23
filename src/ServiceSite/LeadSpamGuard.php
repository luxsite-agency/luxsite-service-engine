<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Відсів ботів і сканерів у формах заявок (доповнює CSRF і LeadRateLimit). Правила — параметр паку `app.lead_spam`
 * (дефолти в DEFAULTS і config/services.yaml, docs/site-pack.md «Форми заявок: захист від ботів»).
 *
 * Сканери беруть форму разом із CSRF-токеном і шлють її за долю секунди, підставляючи в поля SQL-проби, теги, довгі
 * ряди цифр; спамери — латинський текст із посиланнями. Перевірки за порядком:
 *  - honeypot `website` (невидиме поле, людина його не бачить);
 *  - підписана мітка часу `_ft` (Twig `lead_form_ts()`): швидше за min_seconds від показу форми людина не заповнює,
 *    старша за max_age_hours — застаріла вкладка чи збережена сторінка. Поля нема (шаблон паку без нього) - перевірка
 *    пропускається, щоб не зламати старі шаблони; підробка підпису - бот;
 *  - телефон: лише цифри й розділювачі, не менше phone_min_digits цифр, префікс із phone_country_codes;
 *  - ім'я без розмітки, SQL і посилань; повідомлення без SQL-проб і тегів-посилань, посилань не більше max_urls
 *    (без кирилиці на uk/ru-сайті — жодного), від 40 символів без кирилиці на uk/ru-сайті — спам (latin_only);
 *  - Cloudflare Turnstile, якщо задано ключі (Turnstile).
 *
 * Що робити зі спрацюванням, вирішує контролер: заявка зберігається зі spam_reason (LeadSpamRecorder), у пошту й CRM
 * не йде; людина бачить подяку без події конверсії, а на RETRY_REASONS — «спробуйте ще раз» (повтор проходить).
 */
final class LeadSpamGuard
{
    public const TIME_FIELD = '_ft';

    /** Дефолти app.lead_spam (пак перекриває лише потрібні ключі). */
    public const array DEFAULTS = [
        'min_seconds' => 3,
        'max_age_hours' => 24,
        'max_urls' => 3,
        'latin_only' => true,
        'phone_min_digits' => 9,
        'phone_country_codes' => [],
        'ip_per_hour' => 5,
        'dedup_seconds' => 600,
        'turnstile' => ['site_key' => '', 'secret_env' => 'TURNSTILE_SECRET'],
    ];

    /** Префікси причин, після яких людина бачить «спробуйте ще раз» і повтор проходить (решта — тиха подяка без конверсії). */
    public const array RETRY_REASONS = ['too-fast', 'time-old', 'phone:short'];

    /** Мови, для яких повідомлення без кирилиці підозріле (latin_only і посилання без кирилиці). */
    private const array CYRILLIC_LOCALES = ['uk', 'ru'];

    private const SQL = '\b(union\s+(all\s+)?select|select\s.+\sfrom|sleep\s*\(|benchmark\s*\(|waitfor\s+delay|information_schema|extractvalue\s*\(|updatexml\s*\(|concat\s*\()';

    /** Посилання: адреса зі схемою чи www. (цілком, щоб домен не рахувався вдруге) або голий домен поширеної зони. */
    private const URL = '~(?:https?://|www\.)\S+|\b[a-z0-9-]+\.(?:com|net|org|info|biz|xyz|top|site|online|shop|store|club|io|me|su|ru|ua|pro|link|live|vip|buzz|icu|cc|tk|ml|ga|cf|gq)\b~i';

    /** @var array{min_seconds: int, max_age_hours: int, max_urls: int, latin_only: bool, phone_min_digits: int, phone_country_codes: list<string>, ip_per_hour: int, dedup_seconds: int, turnstile: array{site_key: string, secret_env: string}} */
    private array $config;

    /** @param array<string, mixed> $config app.lead_spam */
    public function __construct(
        #[Autowire(param: 'app.lead_spam')]
        array $config,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        private readonly LoggerInterface $logger,
        private readonly ?Turnstile $turnstile = null,
    ) {
        $this->config = self::normalize($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{min_seconds: int, max_age_hours: int, max_urls: int, latin_only: bool, phone_min_digits: int, phone_country_codes: list<string>, ip_per_hour: int, dedup_seconds: int, turnstile: array{site_key: string, secret_env: string}}
     */
    public static function normalize(array $config): array
    {
        $c = $config + self::DEFAULTS;
        $codes = [];
        foreach ((array) $c['phone_country_codes'] as $code) {
            $code = preg_replace('/\D/', '', (string) $code) ?? '';
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        $turnstile = (\is_array($c['turnstile']) ? $c['turnstile'] : []) + self::DEFAULTS['turnstile'];

        return [
            'min_seconds' => max(0, (int) $c['min_seconds']),
            'max_age_hours' => max(0, (int) $c['max_age_hours']),
            'max_urls' => max(0, (int) $c['max_urls']),
            'latin_only' => (bool) $c['latin_only'],
            'phone_min_digits' => max(0, (int) $c['phone_min_digits']),
            'phone_country_codes' => $codes,
            'ip_per_hour' => max(0, (int) $c['ip_per_hour']),
            'dedup_seconds' => max(0, (int) $c['dedup_seconds']),
            'turnstile' => ['site_key' => trim((string) $turnstile['site_key']), 'secret_env' => trim((string) $turnstile['secret_env'])],
        ];
    }

    /** Вікно дубля phone + місто, с (app.lead_spam.dedup_seconds). */
    public function dedupSeconds(): int
    {
        return $this->config['dedup_seconds'];
    }

    /** Ліміт заявок (і спам-записів) з однієї IP за годину (app.lead_spam.ip_per_hour); 0 = без ліміту. */
    public function ipPerHour(): int
    {
        return $this->config['ip_per_hour'];
    }

    /** Після цієї причини людині кажуть «спробуйте ще раз» (повтор проходить), а не ведуть на подяку. */
    public static function isRetryReason(string $reason): bool
    {
        foreach (self::RETRY_REASONS as $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Значення для прихованого поля `_ft`: час показу форми + підпис. */
    public function formToken(?int $now = null): string
    {
        $t = (string) ($now ?? time());

        return $t . '.' . $this->sign($t);
    }

    /**
     * Причина блокування або null, якщо заявка схожа на людську.
     *
     * @param string       $locale       мова сторінки форми (uk|ru|en…): для uk/ru діють правила про кирилицю
     * @param list<string> $strictFields поля, де не буває розмітки, SQL і посилань (ім'я)
     * @param list<string> $textFields   вільний текст (повідомлення)
     */
    public function blockReason(Request $request, string $locale = 'uk', array $strictFields = ['name'], array $textFields = ['message'], ?int $now = null): ?string
    {
        $post = $request->request;

        if ((string) $post->get('website', '') !== '') {
            return 'honeypot';
        }

        $ft = (string) $post->get(self::TIME_FIELD, '');

        if ($ft !== '') {
            $parts = explode('.', $ft, 2);

            if (\count($parts) !== 2 || !ctype_digit($parts[0]) || !hash_equals($this->sign($parts[0]), $parts[1])) {
                return 'time-token';
            }

            $elapsed = ($now ?? time()) - (int) $parts[0];

            if ($this->config['min_seconds'] > 0 && $elapsed < $this->config['min_seconds']) {
                return 'too-fast ' . $elapsed . 's';
            }
            if ($this->config['max_age_hours'] > 0 && $elapsed > $this->config['max_age_hours'] * 3600) {
                return 'time-old ' . intdiv($elapsed, 3600) . 'h';
            }
        }

        if (null !== $reason = $this->phoneReason((string) $post->get('phone', ''))) {
            return $reason;
        }

        foreach ($strictFields as $field) {
            $value = (string) $post->get($field, '');

            if ($value === '') {
                continue;
            }
            if (preg_match('~[<>{}|;=\\\\]|--|/\*|[0-9]{8,}~', $value) || preg_match('/' . self::SQL . '/i', $value)) {
                return 'junk:' . $field;
            }
            if ($this->config['max_urls'] > 0 && preg_match(self::URL, $value)) {
                return 'url:' . $field;
            }
        }

        $cyrillicExpected = \in_array($locale, self::CYRILLIC_LOCALES, true);

        foreach ($textFields as $field) {
            $value = trim((string) $post->get($field, ''));

            if ($value === '') {
                continue;
            }
            if (preg_match('/' . self::SQL . '/i', $value)
                || preg_match('~<\s*/?\s*(script|a|iframe|img|svg)\b|\[url[=\]]|\'\s*(or|and)\s+\'?\d~i', $value)) {
                return 'junk:' . $field;
            }
            $cyrillic = preg_match('/\p{Cyrillic}/u', $value) === 1;
            if ($this->config['max_urls'] > 0) {
                $urls = preg_match_all(self::URL, $value);
                if ($urls >= $this->config['max_urls'] || ($urls > 0 && $cyrillicExpected && $this->config['latin_only'] && !$cyrillic)) {
                    return 'url:' . $field;
                }
            }
            if ($this->config['latin_only'] && $cyrillicExpected && !$cyrillic && mb_strlen($value) >= 40) {
                return 'latin-only';
            }
        }

        if ($this->turnstile !== null && $this->turnstile->enabled()) {
            return $this->turnstile->verify($request);
        }

        return null;
    }

    /** Телефон: символи, кількість цифр, префікс країни. */
    private function phoneReason(string $phone): ?string
    {
        if ($phone !== '' && !preg_match('/^[0-9+()\s.\-]{1,32}$/', $phone)) {
            return 'junk:phone';
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (\strlen($digits) < $this->config['phone_min_digits']) {
            return 'phone:short';
        }
        if ($this->config['phone_country_codes'] !== []) {
            // 00 — міжнародний префікс замість +
            $number = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
            foreach ($this->config['phone_country_codes'] as $code) {
                if (str_starts_with($number, $code)) {
                    return null;
                }
            }

            return 'phone:country';
        }

        return null;
    }

    public function logBlocked(Request $request, string $reason, string $form, bool $stored): void
    {
        $this->logger->warning('lead.blocked', [
            'reason' => $reason,
            'form' => $form,
            'stored' => $stored,
            'host' => $request->getHost(),
            'ip' => $request->getClientIp(),
            'ua' => mb_substr((string) $request->headers->get('user-agent', ''), 0, 160),
            'name' => mb_substr((string) $request->request->get('name', ''), 0, 80),
        ]);
    }

    private function sign(string $t): string
    {
        return substr(hash_hmac('sha256', 'lead-ft|' . $t, $this->secret), 0, 20);
    }
}
