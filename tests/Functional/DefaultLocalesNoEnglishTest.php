<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceItem;

/**
 * Типове розгортання [uk, ru]: англійських адрес нема навіть у записів з заповненими en-полями — /en/ 404, без hreflang="en",
 * без EN у перемикачі й sitemap. Сайти на рушії, що не вмикали en, поводяться як до en.
 */
final class DefaultLocalesNoEnglishTest extends SeoTestCase
{
    public function testNoEnglishUrlsWithoutEnInLocales(): void
    {
        $this->em()->find(ServiceItem::class, $this->itemId)->setSlugEn('english-item')->setTitleEn('English item');
        $this->em()->flush();
        $this->em()->clear();

        foreach (['/en/', '/en/services/', '/en/english-item/'] as $path) {
            self::assertSame(404, $this->http('GET', 'https://' . $this->apex . $path)->getStatusCode(), $path);
        }
        $html = (string) $this->http('GET', 'https://' . $this->apex . '/')->getContent();
        self::assertStringNotContainsString('hreflang="en"', $html);
        self::assertStringNotContainsString('>EN</a>', $html);
        self::assertStringContainsString('hreflang="ru-UA"', $html);
        $sitemap = (string) $this->http('GET', 'https://' . $this->apex . '/sitemap.xml')->getContent();
        self::assertStringNotContainsString('/en/', $sitemap);
        self::assertStringNotContainsString('hreflang="en"', $sitemap);
    }
}
