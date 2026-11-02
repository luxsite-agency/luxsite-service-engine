<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\CatalogUrls;
use PHPUnit\Framework\TestCase;

final class CatalogUrlsTest extends TestCase
{
    public function testReservedSlugsOnlyInRootScheme(): void
    {
        $root = new CatalogUrls('root', 'news');
        self::assertTrue($root->isReservedCategorySlug('services'));
        self::assertTrue($root->isReservedCategorySlug('news'), 'префікс блогу паку');
        self::assertTrue($root->isReservedCategorySlug('blog'));
        self::assertTrue($root->isReservedCategorySlug('ru'));
        self::assertTrue($root->isReservedCategorySlug('About'));
        self::assertTrue($root->isReservedCategorySlug('/faq/'));
        self::assertFalse($root->isReservedCategorySlug('cargo'));
        self::assertFalse($root->isReservedCategorySlug(''));
        self::assertFalse($root->isReservedCategorySlug(null));

        $nested = new CatalogUrls('nested');
        self::assertSame([], $nested->reservedCategorySlugs());
        self::assertFalse($nested->isReservedCategorySlug('services'));
    }

    /** Схема slug: послуга й категорія від кореня одним сегментом; заборонені ті самі слаги, що в root */
    public function testSlugScheme(): void
    {
        $slug = new CatalogUrls('slug', 'novosti');
        self::assertTrue($slug->isSlug());
        self::assertFalse($slug->isRoot());
        self::assertFalse($slug->isNested(), 'у адресі послуги категорії нема');
        self::assertTrue($slug->hasCategoryPages());
        self::assertTrue($slug->categoriesAtRoot());
        self::assertTrue($slug->itemsAtRoot());
        self::assertTrue($slug->isReservedCategorySlug('novosti'), 'префікс блогу паку');
        self::assertTrue($slug->isReservedCategorySlug('contacts'));
        self::assertContains('novosti', $slug->reservedSegments());

        foreach (['flat' => [false, false, false], 'nested' => [true, false, false], 'root' => [true, true, false]] as $scheme => [$pages, $catsRoot, $itemsRoot]) {
            $u = new CatalogUrls($scheme);
            self::assertSame($pages, $u->hasCategoryPages(), $scheme . ': сторінки категорій');
            self::assertSame($catsRoot, $u->categoriesAtRoot(), $scheme . ': категорії в корені');
            self::assertSame($itemsRoot, $u->itemsAtRoot(), $scheme . ': послуги в корені');
        }
        // фіксовані сегменти зайняті за будь-якої схеми (сторінки app.pages живуть у корені завжди)
        self::assertContains('about', (new CatalogUrls('flat'))->reservedSegments());
    }

    /** Слаг послуги зі шляху сторінки заявки (назва послуги в CRM) — під кожну схему */
    public function testItemSlugFromPath(): void
    {
        $flat = new CatalogUrls('flat');
        self::assertSame('x', $flat->itemSlugFromPath('/services/x/'));
        self::assertSame('x', $flat->itemSlugFromPath('/ru/services/x/'));
        self::assertNull($flat->itemSlugFromPath('/services/'));
        self::assertNull($flat->itemSlugFromPath('/x/'));
        self::assertSame('x', (new CatalogUrls('nested'))->itemSlugFromPath('/services/cat/x/'));
        $root = new CatalogUrls('root');
        self::assertSame('x', $root->itemSlugFromPath('/cat/x/'));
        self::assertSame('x', $root->itemSlugFromPath('/ru/cat/x/'));
        self::assertNull($root->itemSlugFromPath('/cat/'));
        self::assertNull($root->itemSlugFromPath('/districts/obolon/'));
        $slug = new CatalogUrls('slug', 'novosti');
        self::assertSame('x', $slug->itemSlugFromPath('/x/'));
        self::assertSame('x', $slug->itemSlugFromPath('/ru/x/'));
        self::assertNull($slug->itemSlugFromPath('/contacts/'));
        self::assertNull($slug->itemSlugFromPath('/novosti/'));
        self::assertNull($slug->itemSlugFromPath('/vakansii/likar/'));
        self::assertNull($slug->itemSlugFromPath('/'));
    }

    /** Схема slug: маршрути послуги й категорії мають один шаблон і розводяться умовою root_slugs */
    public function testSlugRoutesUseRootSlugsCondition(): void
    {
        $yaml = (string) file_get_contents(\dirname(__DIR__, 2) . '/config/routes/catalog/slug.yaml');
        self::assertStringContainsString("service('root_slugs').matches('item', request, params)", $yaml);
        self::assertStringContainsString("service('root_slugs').matches('category', request, params)", $yaml);
        self::assertSame(1, preg_match("~sv_service:.*?sv_category:~s", $yaml), 'послуга перевіряється раніше за категорію');
    }

    /**
     * Список RESERVED_SEGMENTS має покривати перші сегменти всіх фіксованих uk-маршрутів сайту
     * (src/Controller/Services): новий фіксований розділ без запису тут у схемі root перекриє категорію.
     */
    public function testReservedSegmentsCoverFixedRoutes(): void
    {
        $segments = [];
        foreach (glob(\dirname(__DIR__, 2) . '/src/Controller/Services/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            preg_match_all("~'uk' => '/([^/'{%]+)~", $src, $m);
            preg_match_all("~path: '/([^/'{%]+)~", $src, $m2);
            preg_match_all("~#\\[Route\\('/([^/'{%]+)~", $src, $m3);
            foreach ([...$m[1], ...$m2[1], ...$m3[1]] as $seg) {
                $segments[$seg] = true;
            }
        }
        self::assertNotEmpty($segments);
        $missing = array_diff(array_keys($segments), CatalogUrls::RESERVED_SEGMENTS);
        self::assertSame([], array_values($missing), 'Додай у CatalogUrls::RESERVED_SEGMENTS (і RESERVED_SEGMENTS у bin/site.d/_validate.py): ' . implode(', ', $missing));
    }

    public function testValidatorMirrorsReservedSegments(): void
    {
        $py = (string) file_get_contents(\dirname(__DIR__, 2) . '/bin/site.d/_validate.py');
        self::assertSame(1, preg_match('~RESERVED_SEGMENTS = \{(.*?)\}~s', $py, $m), 'bin/site.d/_validate.py: RESERVED_SEGMENTS');
        preg_match_all('~"([^"]+)"~', $m[1], $q);
        $a = CatalogUrls::RESERVED_SEGMENTS;
        $b = $q[1];
        sort($a);
        sort($b);
        self::assertSame($a, $b, 'RESERVED_SEGMENTS у CatalogUrls і bin/site.d/_validate.py розійшлися');
    }
}
