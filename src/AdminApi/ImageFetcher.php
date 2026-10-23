<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Завантаження зображення за URL для ImageService без SSRF: лише http/https; хост резолвиться ДО запиту, приватні,
 * локальні та службові адреси відкидаються (10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, ::1, fc00::/7, fe80::/10,
 * 0/8, 100.64/10, 224/4, 240/4, IPv4-mapped IPv6); запит іде на перевірену IP (resolve — захист від DNS rebinding);
 * редиректи — вручну, кожен хоп перевіряється знову, не більше 3; таймаут 10 с; тіло читається потоком і обривається
 * понад ліміт. Повертає шлях до тимчасового файлу — викликач його прибирає.
 */
final class ImageFetcher
{
    public const int MAX_REDIRECTS = 3;
    public const int TIMEOUT = 10;

    private const array BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.168.0.0/16',
        '224.0.0.0/4', '240.0.0.0/4', '::/128', '::1/128', 'fc00::/7', 'fe80::/10', '::ffff:0:0/96', '64:ff9b::/96',
    ];

    public function __construct(#[Autowire(service: 'app.image_http_client')] private readonly HttpClientInterface $http)
    {
    }

    /** @return string шлях до тимчасового файлу з тілом відповіді */
    public function fetch(string $url, int $maxBytes): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            [$host, $ip] = $this->check($url);
            $response = $this->http->request('GET', $url, [
                'timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT, 'max_redirects' => 0,
                'resolve' => [$host => $ip],
                'headers' => ['Accept' => 'image/jpeg, image/png, image/webp, image/*;q=0.8, */*;q=0.1'],
            ]);
            try {
                $status = $response->getStatusCode();
            } catch (\Throwable $e) {
                throw new ApiProblem('url: не вдалося завантажити — ' . $this->reason($e), 422);
            }
            $headers = $response->getHeaders(false);
            if (\in_array($status, [301, 302, 303, 307, 308], true)) {
                $response->cancel();
                $location = trim($headers['location'][0] ?? '');
                if ($location === '' || $hop === self::MAX_REDIRECTS) {
                    throw new ApiProblem('url: понад ' . self::MAX_REDIRECTS . ' редиректів або редирект без Location', 422);
                }
                $url = $this->resolve($url, $location);
                continue;
            }
            if ($status !== 200) {
                $response->cancel();
                throw new ApiProblem('url: сервер відповів ' . $status, 422);
            }
            if ((int) ($headers['content-length'][0] ?? 0) > $maxBytes) {
                $response->cancel();
                throw new ApiProblem(sprintf('url: файл понад %d МБ (Content-Length)', intdiv($maxBytes, 1_000_000)), 413);
            }

            return $this->download($response, $maxBytes);
        }
        throw new ApiProblem('url: понад ' . self::MAX_REDIRECTS . ' редиректів', 422);
    }

    private function download(\Symfony\Contracts\HttpClient\ResponseInterface $response, int $maxBytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'img-url-');
        if ($tmp === false) {
            throw new ApiProblem('Нема місця для тимчасового файлу', 500);
        }
        $fh = fopen($tmp, 'wb');
        $size = 0;
        try {
            foreach ($this->http->stream($response, self::TIMEOUT) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new ApiProblem('url: таймаут ' . self::TIMEOUT . ' с', 422);
                }
                $data = $chunk->getContent();
                $size += \strlen($data);
                if ($size > $maxBytes) {
                    $response->cancel();
                    throw new ApiProblem(sprintf('url: файл понад %d МБ', intdiv($maxBytes, 1_000_000)), 413);
                }
                if ($data !== '') {
                    fwrite($fh, $data);
                }
            }
        } catch (ApiProblem $e) {
            fclose($fh);
            @unlink($tmp);
            throw $e;
        } catch (\Throwable $e) {
            fclose($fh);
            @unlink($tmp);
            throw new ApiProblem('url: не вдалося завантажити — ' . $this->reason($e), 422);
        }
        fclose($fh);

        return $tmp;
    }

    /**
     * Схема, хост, резолв і перевірка кожної адреси.
     *
     * @return array{0: string, 1: string} хост, перевірена IP
     */
    private function check(string $url): array
    {
        $p = parse_url($url) ?: [];
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if (!\in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new ApiProblem('url: лише http(s)://хост/шлях', 422);
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new ApiProblem('url: облікові дані в адресі не приймаються', 422);
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new ApiProblem('url: локальні адреси заборонені', 422);
        }
        $ips = filter_var($host, \FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolveHost($host);
        if ($ips === []) {
            throw new ApiProblem('url: хост не резолвиться: ' . $host, 422);
        }
        foreach ($ips as $ip) {
            if (self::isBlocked($ip)) {
                throw new ApiProblem('url: приватні й локальні адреси заборонені (' . $host . ' → ' . $ip . ')', 422);
            }
        }

        return [$host, $ips[0]];
    }

    /** @return list<string> */
    private function resolveHost(string $host): array
    {
        $ips = [];
        // на невдачі обидві функції віддають false ((array) false = [false] → TypeError), тому ?: []
        foreach (@dns_get_record($host, \DNS_A | \DNS_AAAA) ?: [] as $rec) {
            if (isset($rec['ip'])) {
                $ips[] = $rec['ip'];
            } elseif (isset($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
        if ($ips === []) {
            foreach (@gethostbynamel($host) ?: [] as $ip) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    public static function isBlocked(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) — перевіряємо як IPv4
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        return filter_var($ip, \FILTER_VALIDATE_IP) === false || IpUtils::checkIp($ip, self::BLOCKED);
    }

    /** Абсолютний URL з Location (абсолютний, //host, /шлях чи відносний). */
    private function resolve(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        $b = parse_url($base) ?: [];
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('~[^/]*$~', '', $b['path'] ?? '/', 1) ?? '/';

        return $origin . ($dir === '' ? '/' : $dir) . $location;
    }

    private function reason(\Throwable $e): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 160);
    }
}
