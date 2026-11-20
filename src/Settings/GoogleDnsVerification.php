<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

use App\Entity\ServiceSite;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Підтвердження сайту в Google Search Console ресурсом «Домен» через DNS TXT (20.11.2026, замість файлів
 * google<hex>.html: ім'я файла Google видає на акаунт, тож воно однакове для всіх доменів акаунта; значення TXT — окреме
 * на кожен домен, тому кілька сайтів одного розгортання підтверджуються незалежно).
 *
 * Значення — налаштування бренду seo.google_dns_verification. Після збереження в адмінці sync() ставить на апекс бренду
 * TXT «google-site-verification=<значення>» у Cloudflare (CLOUDFLARE_DNS_TOKEN: лише Zone:Read + DNS:Edit і лише зони
 * цього розгортання), спершу нові записи, потім видалення зайвих — лише тих, що рушій ставив сам: TXT на імені апекса з
 * міткою в comment. Чужі TXT (SPF, DKIM, інші google-site-verification) і записи на інших іменах зони не чіпаються ніколи.
 * Міста не отримують записів: TXT на апексі покриває всі піддомени. Збій Cloudflare не ламає збереження: результат —
 * статус по домену з лічильниками; токен ніде не показується й не логується. Сторінка налаштувань у Cloudflare не ходить:
 * стан — з останньої синхронізації чи перевірки (кеш STATUS_TTL) або «невідомо» до кнопки refresh(). check() — чи видно
 * запис у публічному DNS (DoH 1.1.1.1, без токена).
 */
final class GoogleDnsVerification
{
    /** Мітка записів, які ставить рушій (поле comment запису Cloudflare); видаляються лише вони. */
    public const string MARKER = 'service-engine: google-site-verification';
    public const string CSRF = 'settings-dns-check';

    public const string STATE_OK = 'ok';
    public const string STATE_PENDING = 'pending';
    public const string STATE_NO_ZONE = 'no_zone';
    public const string STATE_ZONE_INACTIVE = 'zone_inactive';
    public const string STATE_DISABLED = 'disabled';
    public const string STATE_ERROR = 'error';
    public const string STATE_CLEARED = 'cleared';
    public const string STATE_UNKNOWN = 'unknown';

    /** Скільки сторінка налаштувань показує останній відомий стан без нового запиту до Cloudflare. */
    public const int STATUS_TTL = 60;

    private const string API = 'https://api.cloudflare.com/client/v4';
    private const string DOH = 'https://1.1.1.1/dns-query';

    /** Токен один раз обрізаний: те саме значення і для isEnabled(), і в заголовку запиту. */
    private readonly string $token;

    public function __construct(
        private readonly SiteSettings $settings,
        #[Autowire(service: 'app.cloudflare_http_client')] private readonly HttpClientInterface $cloudflare,
        #[Autowire(service: 'app.doh_http_client')] private readonly HttpClientInterface $doh,
        #[Autowire(service: 'monolog.logger.security_audit')] private readonly LoggerInterface $audit,
        #[Autowire(service: 'cache.app')] private readonly CacheItemPoolInterface $cache,
        #[Autowire('%env(CLOUDFLARE_DNS_TOKEN)%')] #[SensitiveParameter] string $token = '',
    ) {
        $this->token = trim($token);
    }

    /** @return list<string> */
    public function values(ServiceSite $site): array
    {
        return SiteSettingKeys::googleDnsValues($this->settings->get($site, SiteSettingKeys::GOOGLE_DNS_VERIFICATION));
    }

    public static function txt(string $value): string
    {
        return SiteSettingKeys::GOOGLE_DNS_PREFIX . $value;
    }

    /**
     * Домени, на які ставиться TXT: апекс бренду (не міста — TXT на апексі покриває піддомени). Dev-хости — ні.
     *
     * @return list<string>
     */
    public function domains(ServiceSite $site): array
    {
        $apex = strtolower(trim($site->getApexDomain(), " .\t\n"));

        return '' === $apex || str_ends_with($apex, '.localhost') || !str_contains($apex, '.') ? [] : [$apex];
    }

    public function isEnabled(): bool
    {
        return '' !== $this->token;
    }

    /**
     * Записати в Cloudflare те, що зараз у налаштуванні (спершу нові, потім видалення своїх зайвих).
     *
     * @return list<DnsStatus>
     */
    public function sync(ServiceSite $site): array
    {
        $values = $this->values($site);
        $out = [];
        foreach ($this->domains($site) as $domain) {
            $status = $this->apply($domain, $values, write: true);
            $this->audit->info('settings.dns_sync', [
                'event' => 'settings.dns_sync',
                'site_id' => $site->getId(),
                'domain' => $domain,
                'state' => $status->state,
                'created' => $status->created,
                'deleted' => $status->deleted,
                'error' => $status->error,
            ]);
            $out[] = $status;
        }
        $this->remember($site, $values, $out);

        return $out;
    }

    /**
     * Стан для сторінки налаштувань без запитів до Cloudflare: без токена — «вимкнено», інакше останній відомий (кеш)
     * або «невідомо».
     *
     * @return list<DnsStatus>
     */
    public function view(ServiceSite $site): array
    {
        $values = $this->values($site);
        if ([] === $values) {
            return [];
        }
        $lines = array_map(self::txt(...), $values);
        if (!$this->isEnabled()) {
            return array_map(static fn (string $domain): DnsStatus => new DnsStatus($domain, self::STATE_DISABLED, $lines), $this->domains($site));
        }
        $item = $this->cache->getItem($this->cacheKey($site, $values));
        if ($item->isHit() && \is_array($cached = $item->get())) {
            return array_map(static fn (array $s): DnsStatus => new DnsStatus((string) $s['domain'], (string) $s['state'], $lines, $s['error'] ?? null, (int) ($s['created'] ?? 0), (int) ($s['deleted'] ?? 0)), $cached);
        }

        return array_map(static fn (string $domain): DnsStatus => new DnsStatus($domain, self::STATE_UNKNOWN, $lines), $this->domains($site));
    }

    /**
     * Прочитати стан з Cloudflare (без змін у DNS) і запам'ятати для сторінки налаштувань — кнопка «Стан у Cloudflare».
     *
     * @return list<DnsStatus>
     */
    public function refresh(ServiceSite $site): array
    {
        $values = $this->values($site);
        if ([] === $values) {
            return [];
        }
        $out = array_map(fn (string $domain): DnsStatus => $this->apply($domain, $values, write: false), $this->domains($site));
        $this->remember($site, $values, $out);

        return $out;
    }

    /**
     * Чи видно кожне значення в публічному DNS (DoH 1.1.1.1, без токена).
     *
     * @return list<array{domain: string, txt: string, visible: bool, error: string|null}>
     */
    public function check(ServiceSite $site): array
    {
        $values = $this->values($site);
        $rows = [];
        foreach ($this->domains($site) as $domain) {
            $seen = [];
            $error = null;
            try {
                $data = $this->doh->request('GET', self::DOH, [
                    'query' => ['name' => $domain, 'type' => 'TXT'],
                    'headers' => ['Accept' => 'application/dns-json'],
                ])->toArray();
                foreach ((array) ($data['Answer'] ?? []) as $answer) {
                    if (\is_array($answer) && 16 === (int) ($answer['type'] ?? 0)) {
                        $seen[] = self::unquote((string) ($answer['data'] ?? ''));
                    }
                }
            } catch (HttpException $e) {
                $error = $this->clean($e->getMessage());
            }
            foreach ($values as $value) {
                $rows[] = ['domain' => $domain, 'txt' => self::txt($value), 'visible' => \in_array(self::txt($value), $seen, true), 'error' => $error];
            }
        }

        return $rows;
    }

    /** @param list<string> $values */
    private function apply(string $domain, array $values, bool $write): DnsStatus
    {
        $lines = array_map(self::txt(...), $values);
        if (!$this->isEnabled()) {
            return new DnsStatus($domain, self::STATE_DISABLED, $lines);
        }
        $created = $deleted = 0;
        try {
            $zone = $this->zone($domain);
            if (null === $zone) {
                return new DnsStatus($domain, self::STATE_NO_ZONE, $lines);
            }
            if ('active' !== $zone['status']) {
                return new DnsStatus($domain, self::STATE_ZONE_INACTIVE, $lines, error: $zone['status']);
            }
            $base = '/zones/' . rawurlencode($zone['id']) . '/dns_records';
            $present = [];
            $stale = [];
            foreach ($this->api('GET', $base, ['query' => ['type' => 'TXT', 'name' => $domain, 'per_page' => 100]]) as $record) {
                // фільтр запиту Cloudflare — не гарантія: ім'я й тип звіряються тут
                if (!\is_array($record) || 'TXT' !== ($record['type'] ?? null) || strtolower((string) ($record['name'] ?? '')) !== $domain) {
                    continue;
                }
                $content = self::unquote((string) ($record['content'] ?? ''));
                $own = self::MARKER === ($record['comment'] ?? null) && str_starts_with($content, SiteSettingKeys::GOOGLE_DNS_PREFIX);
                if ($own && !\in_array($content, $lines, true)) {
                    $stale[] = (string) ($record['id'] ?? '');
                    continue;
                }
                $present[$content] = true;
            }
            $missing = array_values(array_filter($lines, static fn (string $line): bool => !isset($present[$line])));
            if (!$write) {
                return new DnsStatus($domain, [] === $lines ? self::STATE_CLEARED : ([] === $missing ? self::STATE_OK : self::STATE_PENDING), $lines);
            }
            // спершу нові, потім видалення: збій посередині не лишає домен без жодного значення
            foreach ($missing as $line) {
                $this->api('POST', $base, ['json' => ['type' => 'TXT', 'name' => $domain, 'content' => $line, 'ttl' => 1, 'comment' => self::MARKER]]);
                ++$created;
            }
            foreach ($stale as $id) {
                $this->api('DELETE', $base . '/' . rawurlencode($id));
                ++$deleted;
            }

            return new DnsStatus($domain, [] === $lines ? self::STATE_CLEARED : self::STATE_OK, $lines, created: $created, deleted: $deleted);
        } catch (CloudflareApiException $e) {
            return new DnsStatus($domain, self::STATE_ERROR, $lines, error: $e->getMessage(), created: $created, deleted: $deleted);
        }
    }

    /**
     * Зона домену в акаунті токена: сам домен, далі батьківські (апекс бренду може бути піддоменом зони).
     *
     * @return array{id: string, status: string}|null
     */
    private function zone(string $domain): ?array
    {
        $labels = explode('.', $domain);
        while (\count($labels) >= 2) {
            $name = implode('.', $labels);
            foreach ($this->api('GET', '/zones', ['query' => ['name' => $name]]) as $zone) {
                if (\is_array($zone) && ($zone['name'] ?? null) === $name && isset($zone['id'])) {
                    return ['id' => (string) $zone['id'], 'status' => (string) ($zone['status'] ?? 'active')];
                }
            }
            array_shift($labels);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function api(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->cloudflare->request($method, self::API . $path, $options + ['auth_bearer' => $this->token]);
            $code = $response->getStatusCode();
            try {
                $data = $response->toArray(false);
            } catch (DecodingExceptionInterface) {
                throw new CloudflareApiException(\sprintf('HTTP %d, відповідь не JSON', $code));
            }
        } catch (CloudflareApiException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw new CloudflareApiException($this->clean('мережа: ' . $e->getMessage()));
        }
        if ($code >= 300 || true !== ($data['success'] ?? false)) {
            $first = \is_array($data['errors'][0] ?? null) ? $data['errors'][0] : [];
            throw new CloudflareApiException($this->clean(trim(\sprintf('HTTP %d %s %s', $code, (string) ($first['code'] ?? ''), (string) ($first['message'] ?? '')))));
        }

        return \is_array($data['result'] ?? null) ? $data['result'] : [];
    }

    /**
     * @param list<string>    $values
     * @param list<DnsStatus> $statuses
     */
    private function remember(ServiceSite $site, array $values, array $statuses): void
    {
        $item = $this->cache->getItem($this->cacheKey($site, $values));
        $item->set(array_map(static fn (DnsStatus $s): array => ['domain' => $s->domain, 'state' => $s->state, 'error' => $s->error, 'created' => $s->created, 'deleted' => $s->deleted], $statuses));
        $item->expiresAfter(self::STATUS_TTL);
        $this->cache->save($item);
    }

    /** @param list<string> $values стан прив'язаний до значень і апекса: змінилось будь-що — «невідомо», а не старий стан */
    private function cacheKey(ServiceSite $site, array $values): string
    {
        return 'gsc_dns_' . (int) $site->getId() . '_' . hash('xxh128', implode(' ', $this->domains($site)) . '|' . implode(' ', $values));
    }

    /** Текст помилки без токена (на випадок, якщо проксі чи клієнт його відлунює) і обрізаний. */
    private function clean(string $text): string
    {
        if ('' !== $this->token) {
            $text = str_replace($this->token, '***', $text);
        }

        return mb_strimwidth(preg_replace('/\s+/', ' ', $text) ?? '', 0, 200, '…');
    }

    private static function unquote(string $content): string
    {
        // Cloudflare і DoH віддають TXT у лапках, довгий — кількома рядками "…" "…"
        return str_replace('" "', '', trim(trim($content), '"'));
    }
}
