<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Pipedrive;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Тонка обгортка над Pipedrive API v1: токен, таймаути, розбір відповіді.
 *
 * Акаунт - клієнта або партнера (токен у .env.local сайту), тож усе створене
 * буде від імені власника токена. Порожній токен = інтеграція вимкнена:
 * так dev, локальний стек і сайти без CRM не ходять у чужий акаунт.
 */
final class PipedriveClient
{
    private const BASE = 'https://api.pipedrive.com/v1/';

    /** Клієнт без логера (app.crm_http_client): у URL - api_token, в журнал він потрапити не має. */
    public function __construct(
        #[Autowire(service: 'app.crm_http_client')]
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'PIPEDRIVE_API_TOKEN')]
        private readonly string $token = '',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->token !== '';
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>|null data відповіді
     */
    public function get(string $path, array $query = []): ?array
    {
        $query['api_token'] = $this->token;

        return $this->call('GET', $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null data відповіді
     */
    public function post(string $path, array $payload): ?array
    {
        return $this->call('POST', $path, [
            'query' => ['api_token' => $this->token],
            'json' => $payload,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function call(string $method, string $path, array $options): ?array
    {
        try {
            $response = $this->http->request($method, self::BASE . $path, $options + [
                'timeout' => 10,
                'max_duration' => 15,
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            // тексти таймаутів HttpClient містять повний URL разом з api_token, а йдуть у crm_error і лог
            throw new PipedriveException(sprintf(
                'Pipedrive %s %s: %s',
                $method,
                $path,
                (string) preg_replace('/api_token=[^&"\'\s]+/', 'api_token=***', $e->getMessage()),
            ));
        }

        if ($status >= 400 || ($body['success'] ?? false) !== true) {
            throw new PipedriveException(sprintf(
                'Pipedrive %s %s: HTTP %d %s',
                $method,
                $path,
                $status,
                (string) ($body['error'] ?? 'без пояснення'),
            ));
        }

        $data = $body['data'] ?? null;

        return \is_array($data) ? $data : null;
    }
}
