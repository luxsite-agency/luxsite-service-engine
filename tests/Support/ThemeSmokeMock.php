<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Рендер сторінок після запису живого шаблону (ThemeService::smoke) у тестах: без веб-сервера й мережі кожна сторінка
 * «рендериться» (config/services.yaml, when@test). Що сайт справді показує, тести перевіряють запитом через ядро.
 */
final class ThemeSmokeMock
{
    public function __invoke(string $method, string $url): MockResponse
    {
        if (str_contains($url, '/sitemap.xml')) {
            return new MockResponse('<?xml version="1.0"?><urlset></urlset>');
        }

        return new MockResponse('<!doctype html><html><body><main>ok</main></body></html>');
    }
}
