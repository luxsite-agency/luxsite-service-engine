<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace Luxsite\GmbReviews;

/**
 * Читає JSON віджета з GMB Manager (контракт v1) з кешем і запасним варіантом.
 *
 * Без залежностей: HTTP через curl або file_get_contents, кеш — будь-який об'єкт з get/set
 * (PSR-16 SimpleCache підходить як є) або вбудований файловий. Якщо наш сервер недоступний —
 * віддаємо останню вдалу відповідь (stale), щоб блок на сайті клієнта не зникав.
 */
final class Client
{
    public const DEFAULT_BASE = 'https://gmb.luxsite.agency';
    public const TTL = 600;
    public const STALE_TTL = 86400 * 7;

    /** @var callable(string): ?string */
    private $http;

    /**
     * @param object|null $cache PSR-16 (get/set) або сумісний; null = файловий кеш у sys_get_temp_dir()
     */
    public function __construct(
        private readonly string $baseUrl = self::DEFAULT_BASE,
        private readonly ?object $cache = null,
        private readonly int $ttl = self::TTL,
        ?callable $http = null,
        private readonly ?string $fileCacheDir = null,
    ) {
        $this->http = $http ?? [$this, 'defaultHttp'];
    }

    public function baseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /** JSON віджета або null, якщо ні свіжого, ні збереженого немає (токен невідомий/вимкнений). */
    public function fetch(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        $key = 'gmbr_'.$token;
        $cached = $this->cacheGet($key);
        if (\is_array($cached) && ($cached['_fetched_at'] ?? 0) > time() - $this->ttl) {
            return $cached;
        }
        $body = ($this->http)($this->baseUrl().'/w/v1/'.$token.'.json');
        $data = \is_string($body) ? json_decode($body, true) : null;
        if (\is_array($data) && isset($data['business'], $data['reviews'])) {
            $data['_fetched_at'] = time();
            $this->cacheSet($key, $data);

            return $data;
        }

        // Наш сервер не відповів — показуємо те, що було (до тижня), і не стукаємо знову на кожен запит:
        // наступна спроба через хвилину (сторінка сайту не чекає по 3 с на кожному відкритті).
        if (\is_array($cached) && ($cached['_stale_since'] ?? $cached['_fetched_at'] ?? 0) > time() - self::STALE_TTL) {
            $this->cacheSet($key, ['_fetched_at' => time() - $this->ttl + 60, '_stale_since' => $cached['_stale_since'] ?? $cached['_fetched_at']] + $cached);

            return $cached;
        }

        return null;
    }

    /**
     * Загальна оцінка, яку віджет показує в шапці блоку (той самий вибір «Яку оцінку показувати» в GMB Manager):
     * {value, count, source, includes_site, name, maps_url} або null — оцінку не показуємо / нема даних / сервер
     * недоступний і кешу нема. Для «5.0 ★★★★★ · 102 відгуки» в інших блоках сайту (головна, послуги).
     *
     * @return array{value: float, count: int, source: string, includes_site: bool, name: string, maps_url: ?string}|null
     */
    public function rating(string $token): ?array
    {
        $data = $this->fetch($token);

        return null === $data ? null : Renderer::rating($data);
    }

    public function postUrl(string $token): string
    {
        return $this->baseUrl().'/w/v1/'.$token.'/reviews';
    }

    private function defaultHttp(string $url): ?string
    {
        if (\function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: gmb-reviews-core/1.0'],
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return \is_string($body) && 200 === $status ? $body : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'header' => "Accept: application/json\r\nUser-Agent: gmb-reviews-core/1.0\r\n", 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $ok = isset($http_response_header[0]) && str_contains($http_response_header[0], ' 200');

        return \is_string($body) && $ok ? $body : null;
    }

    private function cacheGet(string $key): mixed
    {
        if (null !== $this->cache && method_exists($this->cache, 'get')) {
            try {
                return $this->cache->get($key);
            } catch (\Throwable) {
                return null;
            }
        }
        $file = $this->cacheFile($key);

        return is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    }

    private function cacheSet(string $key, array $value): void
    {
        if (null !== $this->cache && method_exists($this->cache, 'set')) {
            try {
                $this->cache->set($key, $value, self::STALE_TTL);
            } catch (\Throwable) {
            }

            return;
        }
        @file_put_contents($this->cacheFile($key), json_encode($value, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function cacheFile(string $key): string
    {
        $dir = $this->fileCacheDir ?? sys_get_temp_dir();

        return rtrim($dir, '/\\').\DIRECTORY_SEPARATOR.$key.'.json';
    }
}
