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

    /** Префікс сторінок команди (app.team_prefix) у схемі root забороняє такий самий slug категорії; порожній — нічого. */
    public function testTeamPrefixReservedInRootScheme(): void
    {
        $root = new CatalogUrls('root', 'news', 'vrachi');
        self::assertTrue($root->isReservedCategorySlug('vrachi'));
        self::assertFalse($root->isReservedCategorySlug('likari'));

        $off = new CatalogUrls('root', 'news');
        self::assertNotContains('', $off->reservedCategorySlugs());
        self::assertFalse($off->isReservedCategorySlug('vrachi'));
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
