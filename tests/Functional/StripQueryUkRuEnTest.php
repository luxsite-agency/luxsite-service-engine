<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\SlugUkRuEnKernel;

/** [uk, ru, en]: /en/services/?foo=1 зрізається так само, як /ru/…; кореневі сторінки — без 301 (рев'ю gls-rev B1) */
final class StripQueryUkRuEnTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return SlugUkRuEnKernel::class;
    }

    public function testEnglishPrefixStripsLikeRussian(): void
    {
        foreach (['/ru/services/?foo=1' => '/ru/services/', '/en/services/?foo=1&utm_source=x' => '/en/services/?utm_source=x'] as $url => $target) {
            $r = $this->http('GET', 'https://' . $this->apex . $url);
            self::assertSame(301, $r->getStatusCode(), $url);
            self::assertStringEndsWith($target, (string) $r->headers->get('Location'), $url);
        }
        self::assertSame(200, $this->http('GET', 'https://' . $this->apex . '/item-' . $this->s . '/?foo=1')->getStatusCode());
    }
}
