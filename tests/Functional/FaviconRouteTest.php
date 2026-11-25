<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FaviconKernel;

final class FaviconRouteTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return FaviconKernel::class;
    }

    public function testRootFaviconRedirectsToThemeIcon(): void
    {
        $response = $this->http('GET', 'https://' . $this->apex . '/favicon.ico');
        self::assertSame(301, $response->getStatusCode());
        self::assertStringStartsWith('/themes/fav-test/favicon.ico', (string) $response->headers->get('Location'));
    }
}
