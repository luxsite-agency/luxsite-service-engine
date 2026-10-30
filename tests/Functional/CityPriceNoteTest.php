<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServicePrice;

/**
 * Умова лише для одного міста (ServiceCity.priceNote): заповнене поле філії видно під цінами на головній і сторінці послуги,
 * у підсумку калькулятора за прайсом і під заголовком форми заявки (інлайн і модалка); на /ru/ без ru-тексту — українська.
 * Апекс без поля рядка не має ніде.
 */
final class CityPriceNoteTest extends SeoTestCase
{
    private const string NOTE = 'Мінімальний виклик — від 1500 грн';

    public function testNoteShownOnlyInCityWithField(): void
    {
        $em = $this->em();
        foreach ([$this->apexCityId, $this->branchCityId] as $cityId) {
            $em->persist((new ServicePrice())
                ->setItem($em->getReference(ServiceItem::class, $this->itemId))
                ->setCity($em->getReference(ServiceCity::class, $cityId))
                ->setNameUk('Виклик майстра')->setPrice('1500')->setInCalculator(true)->setActive(true));
        }
        $em->persist($this->city($this->branchCityId)->setPriceNoteUk('  ' . self::NOTE . ' '));
        $em->persist($this->city($this->apexCityId)->setPriceNoteUk('   '));
        $em->flush();
        $em->clear();
        self::assertNull($this->city($this->apexCityId)->getPriceNoteUk(), 'пробіли в полі = порожньо');

        $item = (string) $this->http('GET', 'https://' . $this->branch . '/services/item-' . $this->s . '/')->getContent();
        self::assertStringContainsString('<p class="meta prices-note city-note">' . self::NOTE . '</p>', $item, 'під цінами послуги');
        self::assertStringContainsString('<p class="form-sub city-note">' . self::NOTE . '</p>', $item, 'під заголовком форми');
        self::assertGreaterThanOrEqual(2, substr_count($item, '<p class="form-sub city-note">'), 'інлайн-форма й модалка');

        $home = (string) $this->http('GET', 'https://' . $this->branch . '/')->getContent();
        self::assertStringContainsString('<p class="meta prices-note city-note">' . self::NOTE . '</p>', $home, 'під цінами на головній');

        $calc = (string) $this->http('GET', 'https://' . $this->branch . '/calculator/')->getContent();
        self::assertStringContainsString('<p class="disclaimer city-note">' . self::NOTE . '</p>', $calc, 'у підсумку калькулятора');

        $ru = (string) $this->http('GET', 'https://' . $this->branch . '/ru/services/item-' . $this->s . '/')->getContent();
        self::assertStringContainsString('<p class="meta prices-note city-note">' . self::NOTE . '</p>', $ru, 'ru порожнє = uk');

        foreach (['/services/item-' . $this->s . '/', '/', '/calculator/'] as $path) {
            $r = $this->http('GET', 'https://' . $this->apex . $path);
            self::assertSame(200, $r->getStatusCode(), $path);
            // не просто «city-note»: калькулятор мультиміського сайту має свій рядок calc-city-note (calc.city_note)
            self::assertStringNotContainsString(self::NOTE, (string) $r->getContent(), 'місто без поля: ' . $path);
            self::assertStringNotContainsString(' city-note">', (string) $r->getContent(), 'місто без поля: ' . $path);
        }
    }
}
