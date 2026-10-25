<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServicePrice;
use App\ServiceSite\CatalogUrls;
use App\Tests\Support\CalcPathKernel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Адреса калькулятора з паку (app.calculator.path: /kalkuliator/): сторінка й форма на ній, обидві типові адреси
 * (/calculator-cleaning/, /calculator/) → 301 з переносом query без службових параметрів, sitemap і посилання лише з нею,
 * перший сегмент зарезервований серед слагів категорій.
 */
final class CalculatorPackPathTest extends SeoTestCase
{
    public function testPackPathRedirectsAndLinks(): void
    {
        $em = $this->em();
        $em->persist((new ServicePrice())->setItem($em->getReference(ServiceItem::class, $this->itemId))->setCity($em->getReference(ServiceCity::class, $this->apexCityId))
            ->setNameUk('Миття вікон')->setPrice('500')->setInCalculator(true)->setActive(true));
        $em->flush();
        $em->clear();

        $kernel = new CalcPathKernel('test', true);
        $kernel->boot();
        $get = static function (string $url) use ($kernel) {
            $request = Request::create($url);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return $response;
        };
        try {
            $r = $get('https://' . $this->apex . '/kalkuliator/');
            self::assertSame(200, $r->getStatusCode());
            self::assertStringContainsString('action="/kalkuliator/lead"', (string) $r->getContent());
            self::assertSame(200, $get('https://' . $this->apex . '/ru/kalkuliator/')->getStatusCode());

            foreach (['/calculator-cleaning/', '/calculator/'] as $old) {
                $r = $get('https://' . $this->apex . $old . '?utm_source=x');
                self::assertSame(301, $r->getStatusCode(), $old);
                self::assertSame('https://' . $this->apex . '/kalkuliator/?utm_source=x', $r->headers->get('Location'), $old);
                $r = $get('https://' . $this->apex . '/ru' . $old);
                self::assertSame(301, $r->getStatusCode(), $old);
                self::assertSame('https://' . $this->apex . '/ru/kalkuliator/', $r->headers->get('Location'), $old);
            }

            $home = (string) $get('https://' . $this->apex . '/')->getContent();
            self::assertStringContainsString('href="/kalkuliator/"', $home);
            self::assertStringNotContainsString('href="/calculator', $home);
            $sitemap = (string) $get('https://' . $this->apex . '/sitemap.xml')->getContent();
            self::assertStringContainsString('/kalkuliator/', $sitemap);
            self::assertStringNotContainsString('/calculator', $sitemap);

            self::assertContains('kalkuliator', (new CatalogUrls('root', 'blog', CalcPathKernel::$calculatorPath))->reservedCategorySlugs());
        } finally {
            $kernel->shutdown();
        }
    }
}
