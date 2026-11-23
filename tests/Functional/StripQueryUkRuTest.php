<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\SlugUkRuKernel;

/**
 * app.clean_urls.strip_query_paths на [uk, ru] — як у main (рев'ю gls-rev B1, 23.11.2026): дефолт рушія зрізає query лише на
 * /services/, /districts/, /blog/ (і /ru/…); кореневі сторінки схем slug/root не отримують 301 через параметр.
 */
final class StripQueryUkRuTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return SlugUkRuKernel::class;
    }

    public function testRootPagesKeepQueryAndCatalogPrefixesStripIt(): void
    {
        foreach (['/item-' . $this->s . '/?foo=1', '/cat-' . $this->s . '/?foo=1', '/ru/item-' . $this->s . '/?foo=1&utm_source=x'] as $url) {
            self::assertSame(200, $this->http('GET', 'https://' . $this->apex . $url)->getStatusCode(), $url . ': без 301 (як main)');
        }
        foreach (['/services/?foo=1' => '/services/', '/ru/services/?foo=1&utm_source=x' => '/ru/services/?utm_source=x'] as $url => $target) {
            $r = $this->http('GET', 'https://' . $this->apex . $url);
            self::assertSame(301, $r->getStatusCode(), $url);
            self::assertStringEndsWith($target, (string) $r->headers->get('Location'), $url);
        }
        self::assertSame('#^/(ru/)?(services|districts|blog)(/|$)#', static::getContainer()->getParameter('app.clean_urls')['strip_query_paths'], 'дефолт рушія — літерал main');
    }
}
