<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use App\ServiceSite\SiteLocales;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class SiteLocalesTest extends TestCase
{
    private const CODES = ['uk' => ['hreflang' => 'uk-UA', 'og' => 'uk_UA', 'schema' => 'uk-UA', 'label' => 'UA']];

    private function stack(string $path, ?ServiceSite $site, ?string $locale = null): RequestStack
    {
        $r = Request::create('https://example.com' . $path);
        if ($site !== null) {
            $r->attributes->set(ServiceHostSubscriber::ATTR_SITE, $site);
        }
        if ($locale !== null) {
            $r->setLocale($locale);
        }
        $s = new RequestStack();
        $s->push($r);

        return $s;
    }

    public function testDefaultsMatchFormerHardcodedValues(): void
    {
        $site = new ServiceSite();
        $l = new SiteLocales($this->stack('/ru/x/', $site, 'ru'), ['uk', 'ru'], self::CODES);
        self::assertSame(['uk', 'ru'], $l->enabled());
        self::assertSame('ru', $l->current());
        self::assertSame('ru', $l->fromPath());
        self::assertSame('uk_UA', $l->code('uk', 'og'));
        self::assertSame('en', $l->code('en', 'hreflang'), 'мова без запису — код як є');
        self::assertSame('EN', $l->code('en', 'label'));

        $other = new SiteLocales($this->stack('/x/', $site, 'de'), ['uk', 'ru']);
        self::assertSame('uk', $other->current(), 'мова поза розгортанням → мова за замовчуванням');
        self::assertSame('uk', $other->fromPath());
    }

    public function testBrandSubsetAndEnglishDeployment(): void
    {
        $site = (new ServiceSite())->setLocales(['de', 'en']);
        $l = new SiteLocales($this->stack('/de/x/', $site, 'de'), ['en', 'de', 'fr']);
        self::assertSame(['en', 'de'], $l->enabled(), 'порядок — як у розгортанні');
        self::assertSame('en', $l->default());
        self::assertSame('de', $l->fromPath());
        self::assertSame('en', $l->ofPath('/contact/'), 'сторінка без префікса — мова розгортання (/lead → «Дякуємо»)');
        self::assertSame('fr', $l->ofPath('/fr/contact/'));
        self::assertSame(['en', 'de', 'fr'], $l->enabled(new ServiceSite()), 'бренд без набору — усі мови');
        self::assertNull((new ServiceSite())->setLocales([])->getLocales(), 'порожній список = усі мови');
    }

    public function testHostWithoutBrandKeepsFrameworkDefault(): void
    {
        $l = new SiteLocales($this->stack('/admin', null), ['en'], [], 'uk');
        self::assertSame('uk', $l->fromPath(), 'адмінка розгортання en лишається українською');
    }
}
