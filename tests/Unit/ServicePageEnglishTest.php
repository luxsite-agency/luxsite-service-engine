<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServicePage;
use App\Entity\ServiceSite;
use App\EventSubscriber\SiteModeSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * ServicePage: англійські поля (23.11.2026). Порожні en-поля віддають uk-значення, порожній slugEn — uk-слаг
 * (інакше sv_page отримує порожній path), SEO-поля фолбеку не мають; sv_page відкрито контентному сайту.
 */
final class ServicePageEnglishTest extends TestCase
{
    public function testEnglishFieldsWinAndFallBackToUk(): void
    {
        $page = (new ServicePage())->setSlugUk('contractors')->setTitleUk('Підрядники')->setLeadUk('Вступ');
        self::assertSame('contractors', $page->slug('en'), 'порожній slugEn → uk-слаг');
        self::assertSame('contractors', $page->path('en'));
        self::assertSame('Підрядники', $page->loc('title', 'en'), 'порожній titleEn → uk');

        $page->setSlugEn('contractors-us')->setTitleEn('Contractors')->setMetaTitleEn('Meta');
        self::assertSame('contractors-us', $page->slug('en'));
        self::assertSame('Contractors', $page->loc('title', 'en'));
        self::assertSame('Вступ', $page->loc('lead', 'en'));
        self::assertSame('Meta', $page->loc('metaTitle', 'en'));
        self::assertNull($page->loc('metaDesc', 'en'), 'SEO-поля без фолбеку');
        self::assertSame('contractors', $page->slug('uk'));
    }

    public function testChildPathUsesEnglishSlugs(): void
    {
        $parent = (new ServicePage())->setSlugUk('contractors')->setSlugEn('pros');
        $child = (new ServicePage())->setSlugUk('springfield')->setParent($parent);
        self::assertSame('pros/springfield', $child->path('en'));
    }

    public function testSlugEnBlankBecomesNull(): void
    {
        self::assertNull((new ServicePage())->setSlugEn('  ')->getSlugEn());
    }

    public function testPageRouteOpenInContentMode(): void
    {
        $content = (new ServiceSite())->setMode(ServiceSite::MODE_CONTENT);
        self::assertTrue(SiteModeSubscriber::allowed($content, 'sv_page'));
        self::assertFalse(SiteModeSubscriber::allowed($content, 'sv_calculator'));
    }
}
