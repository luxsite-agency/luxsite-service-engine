<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCalcType;
use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServiceLead;
use App\Entity\ServicePrice;
use App\Entity\ServiceSite;

/**
 * Калькулятор «за прайсом» (режим обирається сам, стандарт рушія з 24.10.2026): 404 і жодних посилань без позицій
 * з in_calculator; з позиціями — сторінка /calculator/, 301 зі старої /calculator-cleaning/ (з query), sitemap лише з дійсною
 * адресою; заявка — сума на сервері з цін БД, прихована в місті послуга не рахується, позиція без ціни — «уточнюється»,
 * обсяг обрізається до max_qty. Режим «за площею» (є типи прибирання) — /calculator-cleaning/, а /calculator/ → 301.
 */
final class CalculatorPricelistTest extends SeoTestCase
{
    public function testNoCalculatorWithoutPricelistRows(): void
    {
        self::assertSame(404, $this->http('GET', 'https://' . $this->apex . '/calculator/')->getStatusCode());
        self::assertSame(404, $this->http('GET', 'https://' . $this->apex . '/calculator-cleaning/')->getStatusCode());
        // звичайна позиція прайсу без in_calculator калькулятора не вмикає
        $this->price($this->itemId, $this->apexCityId, 'Без калькулятора', '100', inCalculator: false);
        self::assertSame(404, $this->http('GET', 'https://' . $this->apex . '/calculator/')->getStatusCode());

        $home = (string) $this->http('GET', 'https://' . $this->apex . '/')->getContent();
        self::assertStringNotContainsString('<p class="prices-calc-link">', $home, 'кнопка «Повний розрахунок» без калькулятора не рендериться');
        self::assertStringNotContainsString('href="/calculator', $home, 'жодного посилання на калькулятор (меню, футер)');
        self::assertStringContainsString('prices-note', $home, 'примітка «ціни орієнтовні» під таблицею цін');
        self::assertStringNotContainsString('/calculator', (string) $this->http('GET', 'https://' . $this->apex . '/sitemap.xml')->getContent());
    }

    public function testPricelistModePageRedirectsAndLinks(): void
    {
        $this->seedPricelist();

        $r = $this->http('GET', 'https://' . $this->apex . '/calculator/');
        self::assertSame(200, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertStringContainsString('Послуга', $html);
        self::assertStringContainsString('Миття вікон', $html);
        self::assertStringContainsString('Лише апекс', $html, 'послуга, прихована лише у філії, на апексі є');
        self::assertStringContainsString('name="qty[', $html);
        self::assertStringContainsString('м²', $html, 'одиниця з прайсу');
        self::assertStringContainsString('шт.', $html, 'порожня одиниця → «шт.»');
        self::assertStringContainsString('За домовленістю', $html, 'позиція без числа показана з її текстом');
        self::assertStringContainsString('Ціни на сайті орієнтовні', $html, 'calc.disclaimer типовий');
        self::assertStringContainsString('action="/calculator/lead"', $html);

        $r = $this->http('GET', 'https://' . $this->apex . '/calculator-cleaning/?utm_source=x');
        self::assertSame(301, $r->getStatusCode());
        self::assertSame('/calculator/?utm_source=x', $r->headers->get('Location'), 'стара адреса → нова з query');
        $r = $this->http('GET', 'https://' . $this->apex . '/ru/calculator-cleaning/');
        self::assertSame(301, $r->getStatusCode());
        self::assertSame('/ru/calculator/', $r->headers->get('Location'));
        self::assertSame(200, $this->http('GET', 'https://' . $this->apex . '/ru/calculator/')->getStatusCode());

        $home = (string) $this->http('GET', 'https://' . $this->apex . '/')->getContent();
        self::assertStringContainsString('href="/calculator/"', $home, 'кнопка й меню ведуть на дійсну адресу');
        self::assertStringNotContainsString('calculator-cleaning', $home);

        $sitemap = (string) $this->http('GET', 'https://' . $this->apex . '/sitemap.xml')->getContent();
        self::assertStringContainsString('https://' . $this->apex . '/calculator/', $sitemap);
        self::assertStringNotContainsString('calculator-cleaning', $sitemap);

        // у філії прихована послуга не показується
        $branch = (string) $this->http('GET', 'https://' . $this->branch . '/calculator/')->getContent();
        self::assertStringContainsString('Миття вікон', $branch);
        self::assertStringNotContainsString('Лише апекс', $branch);
    }

    public function testLeadIsRecomputedOnServer(): void
    {
        $ids = $this->seedPricelist();

        // заявка з хоста філії: прихована там послуга в розрахунок не йде, навіть якщо її id прийшов з форми
        $page = (string) $this->http('GET', 'https://' . $this->branch . '/calculator/')->getContent();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));
        $r = $this->http('POST', 'https://' . $this->branch . '/calculator/lead', [
            '_token' => html_entity_decode($m[1]),
            'loc' => 'uk',
            'phone' => '+380 67 000 11 22',
            'name' => 'Тест',
            'qty' => [
                $ids['windows'] => '3',
                $ids['tbd'] => '1',
                $ids['hidden'] => '2',
                $ids['capped'] => '99', // max_qty 5 → 5
                '999999999' => '7',
            ],
            // ціна з форми ігнорується
            'price' => ['x' => '1'],
        ], ['REMOTE_ADDR' => '10.9.' . random_int(0, 255) . '.' . random_int(1, 254)]);
        self::assertSame(302, $r->getStatusCode(), (string) $r->getContent());
        self::assertSame('/thank-you/?src=calc', $r->headers->get('Location'));

        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->branchCityId], ['id' => 'DESC']);
        self::assertInstanceOf(ServiceLead::class, $lead);
        $message = (string) $lead->getMessage();
        self::assertStringContainsString('Миття вікон × 3 м² = 1500 грн', $message);
        self::assertStringContainsString('Виклик майстра × 1 шт. = ціна уточнюється', $message);
        self::assertStringContainsString('Демонтаж × 5 шт. = 1000 грн', $message, 'обсяг обрізано до max_qty');
        self::assertStringNotContainsString('Лише апекс', $message, 'прихована в місті послуга не рахується');
        self::assertStringContainsString('Разом: від 2500 грн', $message);
        self::assertStringContainsString('+ 1 позицій', $message);
        self::assertStringContainsString('Категорія / Послуга:', $message);

        // порожній вибір → назад на калькулятор з помилкою, заявки нема
        $before = \count($this->em()->getRepository(ServiceLead::class)->findBy(['city' => $this->apexCityId]));
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/calculator/')->getContent();
        preg_match('/name="_token" value="([^"]+)"/', $page, $m);
        $r = $this->http('POST', 'https://' . $this->apex . '/calculator/lead', [
            '_token' => html_entity_decode($m[1]), 'loc' => 'uk', 'phone' => '+380670001133', 'qty' => [$ids['windows'] => '0'],
        ], ['REMOTE_ADDR' => '10.8.' . random_int(0, 255) . '.' . random_int(1, 254)]);
        self::assertSame(302, $r->getStatusCode());
        self::assertSame('/calculator/', $r->headers->get('Location'));
        self::assertCount($before, $this->em()->getRepository(ServiceLead::class)->findBy(['city' => $this->apexCityId]));
    }

    public function testAreaModeKeepsLegacyPathAndRedirectsNewOne(): void
    {
        $this->seedPricelist();
        $em = $this->em();
        $type = (new ServiceCalcType())->setSite($em->getReference(ServiceSite::class, $this->siteId))->setNameUk('Генеральне')->setPricePerSqm('50')->setMinPrice('1000')->setActive(true);
        $em->persist($type);
        $em->flush();
        $em->clear();

        $r = $this->http('GET', 'https://' . $this->apex . '/calculator-cleaning/');
        self::assertSame(200, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertStringContainsString('name="area"', $html, 'режим за площею');
        self::assertStringNotContainsString('name="qty[', $html);
        self::assertStringContainsString('action="/calculator-cleaning/lead"', $html);

        $r = $this->http('GET', 'https://' . $this->apex . '/calculator/?type=1');
        self::assertSame(301, $r->getStatusCode());
        self::assertSame('/calculator-cleaning/?type=1', $r->headers->get('Location'));

        $home = (string) $this->http('GET', 'https://' . $this->apex . '/')->getContent();
        self::assertStringContainsString('href="/calculator-cleaning/"', $home);
        self::assertStringNotContainsString('href="/calculator/"', $home);
        $sitemap = (string) $this->http('GET', 'https://' . $this->apex . '/sitemap.xml')->getContent();
        self::assertStringContainsString('/calculator-cleaning/', $sitemap);
        self::assertStringNotContainsString($this->apex . '/calculator/', $sitemap);
    }

    /** @return array{windows: int, tbd: int, hidden: int, capped: int} */
    private function seedPricelist(): array
    {
        // id для заявки — позиції філії (тест шле форму з хоста філії)
        $this->price($this->itemId, $this->apexCityId, 'Миття вікон', '500', unit: 'м²', maxQty: 100);
        $windows = $this->price($this->itemId, $this->branchCityId, 'Миття вікон', '500', unit: 'м²', maxQty: 100);
        $tbd = $this->price($this->itemId, $this->branchCityId, 'Виклик майстра', null, priceText: 'За домовленістю');
        $this->price($this->itemId, $this->apexCityId, 'Виклик майстра', null, priceText: 'За домовленістю');
        $hidden = $this->price($this->hiddenItemId, $this->branchCityId, 'Лише апекс робота', '300');
        $this->price($this->hiddenItemId, $this->apexCityId, 'Лише апекс робота', '300');
        $capped = $this->price($this->itemId, $this->branchCityId, 'Демонтаж', '200', maxQty: 5);
        $this->em()->clear();

        return ['windows' => $windows, 'tbd' => $tbd, 'hidden' => $hidden, 'capped' => $capped];
    }

    private function price(int $itemId, int $cityId, string $name, ?string $price, ?string $priceText = null, ?string $unit = null, int $maxQty = 30, bool $inCalculator = true): int
    {
        $em = $this->em();
        $row = (new ServicePrice())
            ->setItem($em->getReference(ServiceItem::class, $itemId))
            ->setCity($em->getReference(ServiceCity::class, $cityId))
            ->setNameUk($name)
            ->setPrice($price)
            ->setPriceTextUk($priceText)
            ->setUnitUk($unit)
            ->setMaxQty($maxQty)
            ->setInCalculator($inCalculator)
            ->setActive(true);
        $em->persist($row);
        $em->flush();

        return (int) $row->getId();
    }
}
