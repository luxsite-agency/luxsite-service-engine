<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Відповіді siteverify для TurnstileKernel (framework.http_client.mock_response_factory): токен віджета «ok» — success,
 * інакше invalid-input-response. Тіла запитів — у $bodies, щоб тест звірив секрет, токен і IP.
 */
final class TurnstileVerifyMock
{
    /** @var list<string> */
    public static array $bodies = [];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $body = (string) ($options['body'] ?? '');
        self::$bodies[] = $body;

        return new MockResponse(str_contains($body, 'response=ok') ? '{"success":true}' : '{"success":false,"error-codes":["invalid-input-response"]}');
    }
}
