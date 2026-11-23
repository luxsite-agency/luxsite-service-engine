<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServicePost;

/** Блог без статей на [uk, ru] (рев'ю gls-rev, BlogController): canonical, hreflang і перемикач — як у main */
final class EmptyBlogAlternatesTest extends SeoTestCase
{
    public function testEmptyBlogKeepsCanonicalHreflangAndSwitcher(): void
    {
        $this->em()->find(ServicePost::class, $this->postId)->setActive(false);
        $this->em()->flush();
        foreach (['' => 'uk', '/ru' => 'ru'] as $prefix => $locale) {
            $r = $this->http('GET', 'https://' . $this->apex . $prefix . '/blog/');
            self::assertSame(200, $r->getStatusCode());
            $html = (string) $r->getContent();
            self::assertStringContainsString('<link rel="canonical" href="https://' . $this->apex . $prefix . '/blog/">', $html, $locale);
            self::assertStringContainsString('hreflang="uk-UA" href="https://' . $this->apex . '/blog/"', $html, $locale);
            self::assertStringContainsString('hreflang="ru-UA" href="https://' . $this->apex . '/ru/blog/"', $html, $locale);
            self::assertMatchesRegularExpression('~<span class="lang-switch">.*>RU</a>~s', $html, $locale);
        }
    }
}
