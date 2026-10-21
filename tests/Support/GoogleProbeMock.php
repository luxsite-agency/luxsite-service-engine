<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Фейкові discovery і JWKS Google для «Перевірити налаштування» в тестах (config/packages/site_settings.yaml, when@test):
 * тести не ходять в інтернет. Кінцеві точки — ті, що в league/oauth2-google.
 */
final class GoogleProbeMock
{
    public function __invoke(string $method, string $url): MockResponse
    {
        if (str_contains($url, '/.well-known/openid-configuration')) {
            return new MockResponse((string) json_encode([
                'issuer' => 'https://accounts.google.com',
                'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_endpoint' => 'https://oauth2.googleapis.com/token',
                'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        }
        if (str_contains($url, '/oauth2/v3/certs')) {
            return new MockResponse((string) json_encode(['keys' => [['kid' => 'test-1'], ['kid' => 'test-2']]]), ['response_headers' => ['content-type' => 'application/json']]);
        }

        return new MockResponse('', ['http_code' => 404]);
    }
}
