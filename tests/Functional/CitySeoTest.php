<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServiceItemCity;
use App\Entity\ServicePrice;
use App\Tests\Support\CitySeoKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сторінки міст, однакові з апексом: H1 калькулятора з містом і рядок «ціни — цього міста» на мультиміському сайті (місто
 * в розробці не рахується); FAQ послуги — місцевий першим, порядок мов як у ov(); з app.catalog.faq_city_fallback: false
 * на філії без місцевого FAQ нема ні блоку, ні FAQPage; з app.legal_noindex_cities: true юридичні сторінки філії —
 * noindex, follow (meta і X-Robots-Tag) і поза sitemap, апекс без змін. Типові значення параметрів поведінку не змінюють.
 */
final class CitySeoTest extends SeoTestCase
{
    public function testCalculatorHeadingNamesCityAndPriceNote(): void
    {
        $this->seedCalculator();
        $em = $this->em();
        $em->persist($this->city($this->branchCityId)->setNameLocUk('Філії')->setNameGenUk('Філії'));
        $em->flush();
        $em->clear();

        $r = $this->http('GET', 'https://' . $this->branch . '/calculator/');
        self::assertSame(200, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertStringContainsString('<h1>Калькулятор вартості у Філії</h1>', $html, 'місто в H1');
        self::assertStringContainsString('<p class="hero-sub calc-city-note">Ціни в калькуляторі - для Філії.', $html, 'рядок про ціни міста');
    }

    public function testCalculatorNoteSkipsCitiesInDevelopment(): void
    {
        $this->seedCalculator();
        $em = $this->em();
        $em->persist($this->city($this->branchCityId)->setNoindex(true));
        $em->flush();
        $em->clear();

        $html = (string) $this->http('GET', 'https://' . $this->apex . '/calculator/')->getContent();
        self::assertStringContainsString('<h1>Калькулятор вартості', $html);
        self::assertStringNotContainsString('calc-city-note', $html, 'живе місто одне — рядка про інші міста нема');
    }

    public function testServiceFaqPrefersCityFaq(): void
    {
        $em = $this->em();
        $item = $em->find(ServiceItem::class, $this->itemId)
            ->setFaqUk([['q' => 'Спільне питання', 'a' => 'Відповідь для всіх']])
            ->setFaqRu([['q' => 'Общий вопрос', 'a' => 'Ответ для всех']]);
        $em->persist($item);
        $em->persist((new ServiceItemCity())->setItem($item)->setCity($this->city($this->branchCityId))
            ->setFaqUk([['q' => 'Місцеве питання', 'a' => 'Відповідь філії']]));
        $em->flush();
        $em->clear();

        $apex = (string) $this->http('GET', 'https://' . $this->apex . '/services/item-' . $this->s . '/')->getContent();
        self::assertStringContainsString('Спільне питання', $apex, 'апекс — FAQ послуги');

        $branch = (string) $this->http('GET', 'https://' . $this->branch . '/services/item-' . $this->s . '/')->getContent();
        self::assertStringContainsString('<summary class="acc-chevron">Місцеве питання</summary>', $branch, 'місто — свій FAQ');
        self::assertStringNotContainsString('Спільне питання', $branch, 'FAQ послуги не змішується з місцевим');
        self::assertStringContainsString('"name":"Місцеве питання"', $branch, 'FAQPage — з місцевого FAQ');

        // /ru/: місцевого ru нема → ru послуги раніше за місцевий uk (як ov() для решти полів)
        $branchRu = (string) $this->http('GET', 'https://' . $this->branch . '/ru/services/item-' . $this->s . '/')->getContent();
        self::assertStringContainsString('Общий вопрос', $branchRu);
        self::assertStringNotContainsString('Місцеве питання', $branchRu, 'на /ru/ без українського FAQ');
    }

    public function testLegalPagesIndexedByDefault(): void
    {
        $r = $this->http('GET', 'https://' . $this->branch . '/privacy-policy/');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringNotContainsString('name="robots"', (string) $r->getContent(), 'типово юридичні сторінки міст індексуються');
        // у debug-ядрі Symfony сам ставить «X-Robots-Tag: noindex» (disallow_search_engine_index) — дивимось лише на наш
        self::assertNotSame('noindex, follow', $r->headers->get('X-Robots-Tag'));

        $sitemap = (string) $this->http('GET', 'https://' . $this->branch . '/sitemap.xml')->getContent();
        self::assertStringContainsString('https://' . $this->branch . '/privacy-policy/', $sitemap);
        self::assertStringContainsString('https://' . $this->branch . '/terms-of-service/', $sitemap);
    }

    public function testStrictParamsDropApexCopiesFromCities(): void
    {
        $em = $this->em();
        $em->persist($em->find(ServiceItem::class, $this->itemId)->setFaqUk([['q' => 'Спільне питання', 'a' => 'Відповідь для всіх']]));
        $em->flush();
        $em->clear();

        $kernel = new CitySeoKernel('test', true);
        $kernel->boot();
        $get = static function (string $url) use ($kernel): Response {
            $request = Request::create($url);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return $response;
        };
        try {
            $branch = (string) $get('https://' . $this->branch . '/services/item-' . $this->s . '/')->getContent();
            self::assertStringNotContainsString('Спільне питання', $branch, 'філія без місцевого FAQ — без FAQ послуги');
            self::assertStringNotContainsString('"@type":"FAQPage"', $branch, 'і без FAQPage');
            $apex = (string) $get('https://' . $this->apex . '/services/item-' . $this->s . '/')->getContent();
            self::assertStringContainsString('Спільне питання', $apex, 'апекс — FAQ послуги як був');
            self::assertStringContainsString('"@type":"FAQPage"', $apex);

            $r = $get('https://' . $this->branch . '/privacy-policy/');
            self::assertSame(200, $r->getStatusCode());
            self::assertSame('noindex, follow', $r->headers->get('X-Robots-Tag'));
            self::assertStringContainsString('<meta name="robots" content="noindex, follow">', (string) $r->getContent());
            self::assertSame('noindex, follow', $get('https://' . $this->branch . '/ru/terms-of-service/')->headers->get('X-Robots-Tag'));
            $sitemap = (string) $get('https://' . $this->branch . '/sitemap.xml')->getContent();
            self::assertStringNotContainsString('privacy-policy', $sitemap);
            self::assertStringNotContainsString('terms-of-service', $sitemap);

            $r = $get('https://' . $this->apex . '/privacy-policy/');
            self::assertNotSame('noindex, follow', $r->headers->get('X-Robots-Tag'), 'апекс індексується');
            self::assertStringNotContainsString('name="robots"', (string) $r->getContent());
            self::assertStringContainsString('https://' . $this->apex . '/privacy-policy/', (string) $get('https://' . $this->apex . '/sitemap.xml')->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    private function seedCalculator(): void
    {
        $em = $this->em();
        foreach ([$this->apexCityId, $this->branchCityId] as $cityId) {
            $em->persist((new ServicePrice())
                ->setItem($em->getReference(ServiceItem::class, $this->itemId))
                ->setCity($em->getReference(ServiceCity::class, $cityId))
                ->setNameUk('Миття вікон')->setPrice('500')->setInCalculator(true)->setActive(true));
        }
        $em->flush();
        $em->clear();
    }
}
