<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

final class PartnerWebhookMock
{
    /** @var list<array{method: string, url: string, options: array}> */
    public static array $requests = [];
    public static int $status = 204;

    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        self::$requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse('', ['http_code' => self::$status]);
    }
}
