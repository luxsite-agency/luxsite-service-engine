<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Settings\SiteSettingKeys;
use App\Settings\SiteSettings;

/** Галочки «Не індексувати» (noindex на сторінці й поза sitemap.xml усіма мовами); lastmod — з дат записів БД. */
final class SitemapTest extends SeoTestCase
{
    public function testNoindexCheckboxesAndLastmod(): void
    {
        $pages = ['/services/', '/reviews/', '/terms-of-service/'];
        // без галочок — як було: сторінки в карті й без нашого заголовка
        foreach ($pages as $page) {
            self::assertStringContainsString($page . '</loc>', (string) $this->http('GET', 'https://' . $this->apex . '/sitemap.xml')->getContent());
            self::assertNotSame('noindex, follow', $this->http('GET', 'https://' . $this->apex . $page)->headers->get('X-Robots-Tag'), $page);
        }
        $settings = static::getContainer()->get(SiteSettings::class);
        foreach (SiteSettingKeys::NOINDEX_ROUTES as $code) {
            $settings->set($this->site(), $code, '1');
        }
        foreach ($pages as $page) {
            foreach (['', '/ru'] as $prefix) {
                $r = $this->http('GET', 'https://' . $this->apex . $prefix . $page);
                self::assertSame(200, $r->getStatusCode(), $prefix . $page);
                self::assertSame('noindex, follow', $r->headers->get('X-Robots-Tag'), $prefix . $page);
                self::assertSame(1, substr_count((string) $r->getContent(), '<meta name="robots" content="noindex, follow">'), $prefix . $page);
            }
        }
        $this->db()->executeStatement("UPDATE service_site SET updated_at = '2025-01-02 10:00:00' WHERE id = ?", [$this->siteId]);
        $this->db()->executeStatement("UPDATE service_city SET updated_at = '2025-02-03 10:00:00' WHERE id = ?", [$this->apexCityId]);
        $this->db()->executeStatement("UPDATE service_category SET updated_at = '2025-03-04 10:00:00' WHERE id = ?", [$this->categoryId]);
        $this->em()->clear();

        $response = $this->http('GET', 'https://' . $this->apex . '/sitemap.xml');
        self::assertSame(200, $response->getStatusCode());
        $xml = (string) $response->getContent();

        foreach (['services', 'reviews', 'terms-of-service'] as $path) {
            self::assertStringNotContainsString('/' . $path . '/</loc>', $xml);
            self::assertStringNotContainsString('/' . $path . '/"', $xml);
        }
        // послуги під /services/ лишаються: виключення — точна адреса, не префікс
        self::assertStringContainsString('/services/item-' . $this->s . '/</loc>', $xml);

        $dates = [];
        foreach (simplexml_load_string($xml)->url as $url) {
            $dates[(string) parse_url((string) $url->loc, \PHP_URL_PATH)] = (string) $url->lastmod;
        }
        foreach (['/about/' => null, '/privacy-policy/' => '2025-01-02', '/ru/privacy-policy/' => '2025-01-02', '/faq/' => '2025-02-03', '/contacts/' => '2025-02-03'] as $path => $date) {
            if ($date !== null) {
                self::assertSame($date, $dates[$path] ?? null, $path);
            }
        }
        // кожна адреса, для якої в фікстурі є дані, має дату
        foreach ($dates as $path => $date) {
            self::assertNotSame('', $date, 'без lastmod: ' . $path);
        }
    }
}
