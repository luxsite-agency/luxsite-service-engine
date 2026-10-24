<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCategory;
use App\Entity\ServiceItem;
use App\Entity\ServicePrice;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Калькулятор «за прайсом» (зразок — rabotniki.ua/uk/calculator/create): категорія → послуга → позиції прайсу міста,
 * у рядку обсяг (0 = не обрано) × ціна з прайсу. Сума — «від N грн» лише з позицій із числом; позиції без числа
 * («за домовленістю») обираються, у підсумку йдуть як «+ ціна уточнюється». Авторитетна сума — ця, серверна:
 * з форми приймаються лише id позиції і обсяг, ціни — з БД (як у CalcPricer для режиму «за площею»).
 */
final class PricelistCalc
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * Групування для шаблону: [{category: ServiceCategory|null, items: [{item: ServiceItem|null, rows: ServicePrice[]}]}].
     * Позиції без послуги — остання група без категорії (заголовок — calc.pricelist.other).
     *
     * @param ServicePrice[] $prices у порядку findPricelistCalculator
     *
     * @return list<array{category: ?ServiceCategory, items: list<array{item: ?ServiceItem, rows: list<ServicePrice>}>}>
     */
    public function groups(array $prices): array
    {
        $groups = [];
        $other = [];
        foreach ($prices as $price) {
            $item = $price->getItem();
            if ($item === null) {
                $other[] = $price;
                continue;
            }
            $category = $item->getCategory();
            $cKey = (int) $category?->getId();
            $iKey = (int) $item->getId();
            $groups[$cKey] ??= ['category' => $category, 'items' => []];
            $groups[$cKey]['items'][$iKey] ??= ['item' => $item, 'rows' => []];
            $groups[$cKey]['items'][$iKey]['rows'][] = $price;
        }
        $out = [];
        foreach ($groups as $group) {
            $out[] = ['category' => $group['category'], 'items' => array_values($group['items'])];
        }
        if ($other !== []) {
            $out[] = ['category' => null, 'items' => [['item' => null, 'rows' => $other]]];
        }

        return $out;
    }

    /** Обсяг із форми, обрізаний у межі 0..maxQty позиції. */
    public static function clampQty(ServicePrice $price, mixed $raw): int
    {
        return max(0, min((int) $raw, max(1, $price->getMaxQty())));
    }

    /**
     * Серверний розрахунок: обрані позиції (обсяг > 0), сума позицій із ціною і кількість позицій без ціни.
     *
     * @param ServicePrice[]   $prices  позиції міста (findPricelistCalculator)
     * @param array<int|string, mixed> $qtyById id позиції → обсяг з форми
     *
     * @return array{rows: list<array{price: ServicePrice, qty: int, sum: ?int}>, total: int, unpriced: int}
     */
    public function quote(array $prices, array $qtyById): array
    {
        $rows = [];
        $total = 0;
        $unpriced = 0;
        foreach ($prices as $price) {
            $qty = self::clampQty($price, $qtyById[$price->getId()] ?? 0);
            if ($qty === 0) {
                continue;
            }
            $sum = null;
            if ($price->getPrice() !== null) {
                $sum = (int) ceil((float) $price->getPrice() * $qty);
                $total += $sum;
            } else {
                ++$unpriced;
            }
            $rows[] = ['price' => $price, 'qty' => $qty, 'sum' => $sum];
        }

        return ['rows' => $rows, 'total' => $total, 'unpriced' => $unpriced];
    }

    /** Одиниця обсягу позиції: з прайсу або типова «шт.» (calc.pricelist.unit_default). */
    public function unit(ServicePrice $price, string $locale): string
    {
        $unit = trim((string) ($price->loc('unit', $locale) ?? ''));

        return $unit !== '' ? $unit : $this->t('calc.pricelist.unit_default', $locale);
    }

    /**
     * Текст розрахунку для message заявки (адмінка, мейл, CRM): категорія / послуга, рядки «назва × обсяг од. = сума»,
     * разом «від N грн» і «+ N позицій: ціна уточнюється».
     *
     * @param array{rows: list<array{price: ServicePrice, qty: int, sum: ?int}>, total: int, unpriced: int} $quote
     */
    public function buildMessage(array $quote, string $locale): string
    {
        $lines = [$this->t('calc.pricelist.message_title', $locale) . ':'];
        $lastHeading = null;
        foreach ($quote['rows'] as $row) {
            $price = $row['price'];
            $item = $price->getItem();
            $heading = $item === null
                ? $this->t('calc.pricelist.other', $locale)
                : trim(implode(' / ', array_filter([$item->getCategory()?->loc('title', $locale), $item->loc('title', $locale)])));
            if ($heading !== $lastHeading) {
                $lines[] = $heading . ':';
                $lastHeading = $heading;
            }
            $amount = $row['sum'] === null
                ? $this->t('calc.pricelist.price_tbd', $locale)
                : $row['sum'] . ' ' . $this->t('catalog.uah', $locale);
            $lines[] = sprintf('- %s × %d %s = %s', $price->loc('name', $locale), $row['qty'], $this->unit($price, $locale), $amount);
        }
        $lines[] = sprintf('%s: %s %d %s', $this->t('calc.total_label', $locale), $this->t('prices.from', $locale), $quote['total'], $this->t('catalog.uah', $locale));
        if ($quote['unpriced'] > 0) {
            $lines[] = sprintf('+ %d %s', $quote['unpriced'], $this->t('calc.pricelist.unpriced_count', $locale));
        }

        return implode("\n", $lines);
    }

    private function t(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], 'servicesite', $locale);
    }
}
