<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\FixedPages;
use App\ServiceSite\SiteLocales;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class FixedPagesTest extends TestCase
{
    public function testDefaultsDisabledAndLocalizedTargets(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn ($route, $params) => '/' . $params['_locale'] . '/' . $route . '/');
        $locales = new SiteLocales(new RequestStack());
        foreach (FixedPages::KEYS as $key) {
            $defaults = new FixedPages($locales, $urls);
            self::assertTrue($defaults->enabled($key));
            self::assertSame('/uk/sv_' . $key . '/', $defaults->path($key));
            $disabled = new FixedPages($locales, $urls, [$key => false]);
            self::assertFalse($disabled->enabled($key));
            self::assertNull($disabled->path($key));
            $redirect = new FixedPages($locales, $urls, [$key => '/custom/?from=engine']);
            self::assertFalse($redirect->enabled($key));
            self::assertSame('/custom/?from=engine', $redirect->path($key, 'uk'));
            self::assertSame('/ru/custom/?from=engine', $redirect->path($key, 'ru'));
        }
        self::assertSame('/ru/custom/', (new FixedPages($locales, $urls, ['faq' => '/ru/custom/']))->path('faq', 'ru'));
        self::assertSame('/custom/', (new FixedPages(new SiteLocales(new RequestStack(), ['en']), $urls, ['faq' => '/custom/']))->path('faq', 'en'));
    }

    public function testExternalTargetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FixedPages(new SiteLocales(new RequestStack()), $this->createMock(UrlGeneratorInterface::class), ['faq' => '//other.example/']);
    }
}
