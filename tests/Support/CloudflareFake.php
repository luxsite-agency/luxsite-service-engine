<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Фейковий Cloudflare API v4 (зони, TXT-записи) і DoH 1.1.1.1 для тестів підтвердження GSC через DNS
 * (config/packages/site_settings.yaml, when@test): тести не ходять в інтернет, а перевіряють саме запити до API.
 * Стан — зони й записи; кожен запит лишається в $requests (метод, шлях, query, тіло, чи був заголовок Authorization).
 */
final class CloudflareFake
{
    /** @var array<string, string> ім'я зони → id */
    public array $zones = [];
    /** @var array<string, list<array{id: string, type: string, name: string, content: string, comment: string|null}>> */
    public array $records = [];
    /** @var list<array{method: string, path: string, query: array<string, mixed>, body: array<string, mixed>|null, auth: string|null}> */
    public array $requests = [];
    /** Код помилки, яким відповідає кожен запит до API (403, 500…); null — працює. */
    public ?int $failWith = null;
    /** @var array<string, int|string> збій лише для методу: код HTTP, 'timeout' (транспорт) або 'nonjson' (502 з HTML) */
    public array $failOn = [];
    /** Cloudflare «проігнорував» фільтр name= у списку записів — віддає TXT усієї зони. */
    public bool $ignoreNameFilter = false;
    /** @var array<string, string> ім'я зони → status (active, pending…) */
    public array $zoneStatus = [];
    private int $seq = 0;

    public function reset(): void
    {
        $this->zones = $this->records = $this->requests = $this->failOn = $this->zoneStatus = [];
        $this->failWith = null;
        $this->ignoreNameFilter = false;
    }

    public function addZone(string $name, string $status = 'active'): string
    {
        $id = 'zone' . substr(md5($name), 0, 12);
        $this->zones[$name] = $id;
        $this->zoneStatus[$name] = $status;
        $this->records[$id] ??= [];

        return $id;
    }

    public function addRecord(string $zone, string $name, string $content, ?string $comment = null, string $type = 'TXT'): string
    {
        $id = 'rec' . ++$this->seq;
        $this->records[$this->zones[$zone]][] = ['id' => $id, 'type' => $type, 'name' => $name, 'content' => $content, 'comment' => $comment];

        return $id;
    }

    /** @return list<string> вміст TXT зони */
    public function txt(string $zone): array
    {
        return array_map(static fn (array $r): string => $r['content'], $this->records[$this->zones[$zone]] ?? []);
    }

    /** @return list<array{method: string, path: string, query: array<string, mixed>, body: array<string, mixed>|null, auth: string|null}> */
    public function requests(string $method): array
    {
        return array_values(array_filter($this->requests, static fn (array $r): bool => $r['method'] === $method));
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $path = (string) ($parts['path'] ?? '');
        $auth = null;
        foreach ((array) ($options['headers'] ?? []) as $header) {
            if (\is_string($header) && 0 === stripos($header, 'authorization:')) {
                $auth = trim(substr($header, 14));
            }
        }
        $body = isset($options['body']) && \is_string($options['body']) && '' !== $options['body'] ? json_decode($options['body'], true) : null;
        $this->requests[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => \is_array($body) ? $body : null, 'auth' => $auth];

        if ('1.1.1.1' === ($parts['host'] ?? '') && '/dns-query' === $path) {
            return $this->doh((string) ($query['name'] ?? ''));
        }
        $fail = $this->failOn[$method] ?? null;
        if ('timeout' === $fail) {
            return new MockResponse('', ['error' => 'Idle timeout reached for "' . $url . '".']);
        }
        if ('nonjson' === $fail) {
            return new MockResponse('<html><body>502 Bad Gateway</body></html>', ['http_code' => 502, 'response_headers' => ['content-type' => 'text/html']]);
        }
        if (\is_int($fail)) {
            return $this->json(['success' => false, 'errors' => [['code' => 10000 + $fail, 'message' => 'Fake failure ' . $fail]], 'result' => null], $fail);
        }
        if (null !== $this->failWith) {
            return $this->json(['success' => false, 'errors' => [['code' => 10000 + $this->failWith, 'message' => 'Fake failure ' . $this->failWith]], 'result' => null], $this->failWith);
        }
        if ('GET' === $method && '/client/v4/zones' === $path) {
            $name = (string) ($query['name'] ?? '');
            $result = isset($this->zones[$name]) ? [['id' => $this->zones[$name], 'name' => $name, 'status' => $this->zoneStatus[$name] ?? 'active']] : [];

            return $this->json(['success' => true, 'errors' => [], 'result' => $result]);
        }
        if (1 === preg_match('#^/client/v4/zones/([^/]+)/dns_records(?:/([^/]+))?$#', $path, $m) && \array_key_exists($m[1], $this->records)) {
            $zone = $m[1];
            if ('GET' === $method && !isset($m[2])) {
                $result = array_values(array_filter($this->records[$zone], fn (array $r): bool => (!isset($query['type']) || $r['type'] === $query['type']) && ($this->ignoreNameFilter || !isset($query['name']) || $r['name'] === $query['name'])));

                return $this->json(['success' => true, 'errors' => [], 'result' => $result]);
            }
            if ('POST' === $method && !isset($m[2]) && \is_array($body)) {
                $record = ['id' => 'rec' . ++$this->seq, 'type' => (string) $body['type'], 'name' => (string) $body['name'], 'content' => (string) $body['content'], 'comment' => $body['comment'] ?? null];
                $this->records[$zone][] = $record;

                return $this->json(['success' => true, 'errors' => [], 'result' => $record]);
            }
            if ('DELETE' === $method && isset($m[2])) {
                $this->records[$zone] = array_values(array_filter($this->records[$zone], static fn (array $r): bool => $r['id'] !== $m[2]));

                return $this->json(['success' => true, 'errors' => [], 'result' => ['id' => $m[2]]]);
            }
        }

        return $this->json(['success' => false, 'errors' => [['code' => 7003, 'message' => 'Could not route']], 'result' => null], 404);
    }

    private function doh(string $name): MockResponse
    {
        $answers = [];
        foreach ($this->records as $records) {
            foreach ($records as $r) {
                if ($r['name'] === $name && 'TXT' === $r['type']) {
                    $answers[] = ['name' => $name, 'type' => 16, 'TTL' => 300, 'data' => '"' . trim($r['content'], '"') . '"'];
                }
            }
        }

        return new MockResponse((string) json_encode(['Status' => 0, 'Answer' => $answers]), ['response_headers' => ['content-type' => 'application/dns-json']]);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $code = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $code, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
