<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Tests\Support\HtmlKeepCommentsKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Чистий HTML на хостах сайтів (25.10.2026): головна, послуга, ru — без коментарів HTML/CSS/JS поза JSON-LD; текст, JSON-LD
 * і скрипти ті самі, що з вимкненою чисткою (app.html.strip_comments: false — ядро HtmlKeepCommentsKernel); адмінка й
 * sitemap не чіпаються; маркери city-raw спожиті й прибрані.
 */
final class HtmlCommentsTest extends SeoTestCase
{
    public function testSitePagesHaveNoCommentsAndKeepContent(): void
    {
        $em = $this->em();
        $city = $em->find(ServiceCity::class, $this->apexCityId);
        self::assertNotNull($city);
        $city->setAddressUk("вул. Тестова, 1\nКиїв");
        $em->flush();
        $em->clear();

        $keep = new HtmlKeepCommentsKernel('test', true);
        $keep->boot();
        $raw = static function (string $url) use ($keep): Response {
            $request = Request::create($url);
            $response = $keep->handle($request);
            $keep->terminate($request, $response);

            return $response;
        };

        try {
            // 404 тут не порівнюється: у тестовому ядрі з debug це сторінка винятку, а не error404.html.twig (чистка 4xx — юніт-тест підписника)
            $pages = ['/' => 200, '/services/item-' . $this->s . '/' => 200, '/ru/' => 200];
            foreach ($pages as $path => $status) {
                $url = 'https://' . $this->apex . $path;
                $clean = $this->http('GET', $url);
                $dirty = $raw($url);
                self::assertSame($status, $clean->getStatusCode(), $path);
                self::assertSame($status, $dirty->getStatusCode(), $path);
                $cleanHtml = (string) $clean->getContent();
                $dirtyHtml = (string) $dirty->getContent();

                if ($status === 200) {
                    self::assertGreaterThan(0, self::comments($dirtyHtml), $path . ': без чистки коментарі є (інакше тест нічого не доводить)');
                }
                self::assertSame(0, self::comments($cleanHtml), $path . ': коментарів у HTML 0');
                self::assertStringNotContainsString('city-raw', $cleanHtml, $path);

                self::assertSame(self::text($dirtyHtml), self::text($cleanHtml), $path . ': текст сторінки той самий');
                self::assertSame(self::jsonLd($dirtyHtml), self::jsonLd($cleanHtml), $path . ': JSON-LD той самий');
                if ($status === 200) {
                    self::assertNotSame([], self::jsonLd($cleanHtml), $path . ': JSON-LD є');
                }
                self::assertSame(self::tags($dirtyHtml), self::tags($cleanHtml), $path . ': ті самі теги в тому ж порядку');
                self::assertSame(substr_count($dirtyHtml, '<script'), substr_count($cleanHtml, '<script'), $path . ': скрипти на місці');
                // Twig-коментарі {# … -#}: сусідні рядки <head> не склеюються в один (рев'ю PR #33 №2)
                self::assertDoesNotMatchRegularExpression('~<(meta|link)\b[^\n]*<(meta|link)\b~', $cleanHtml, $path . ': по одному <meta>/<link> на рядок');
            }
            self::assertStringContainsString('Тестова, 1', (string) $this->http('GET', 'https://' . $this->apex . '/')->getContent(), 'адреса з city-raw лишилась');

            // поза чисткою: sitemap (XML) і адмінка
            $sitemap = (string) $this->http('GET', 'https://' . $this->apex . '/sitemap.xml')->getContent();
            self::assertStringContainsString('<?xml', $sitemap);
            $login = $this->http('GET', 'https://' . $this->adminHost . '/login');
            self::assertSame(200, $login->getStatusCode());
            self::assertSame(self::noToken((string) $raw('https://' . $this->adminHost . '/login')->getContent()), self::noToken((string) $login->getContent()), 'адмінка: як без чистки');
        } finally {
            $keep->shutdown();
        }
    }

    /** `<!--` (крім умовних), `/*` і рядки `//` поза JSON-LD — лічильник, як у bin/site doctor. */
    private static function comments(string $html): int
    {
        $noLd = (string) preg_replace('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>.*?</script>~is', '', $html);

        return preg_match_all('~<!--(?!\[if|<!\[endif)~', $noLd) + preg_match_all('~/\*~', $noLd) + preg_match_all('~^\s*//~m', $noLd);
    }

    private static function text(string $html): string
    {
        $noCode = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        $noCode = (string) preg_replace('~<!--.*?-->~s', '', $noCode);

        return trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($noCode))));
    }

    /** @return list<mixed> */
    private static function jsonLd(string $html): array
    {
        preg_match_all('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $m);

        return array_map(static fn (string $json) => json_decode($json, true, 512, \JSON_THROW_ON_ERROR), $m[1]);
    }

    /** @return list<string> */
    private static function tags(string $html): array
    {
        $noCode = (string) preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        preg_match_all('~<[a-zA-Z/][^>]*>~', $noCode, $m);

        return array_map(static fn (string $t) => self::noToken($t), $m[0]);
    }

    private static function noToken(string $html): string
    {
        return (string) preg_replace('~(_token|_csrf_token|_ft)" value="[^"]+"~', '$1" value="x"', $html);
    }
}
