<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCalcFactor;
use App\Entity\ServiceCalcType;

/**
 * Розрахунок вартості клінінгу. Єдине джерело формули для бекенду
 * (JS у шаблоні дублює її для підрахунку наживо, але авторитетним є сервер):
 *
 *   base = площа x ціна_за_м² x Π(множники обраних коефіцієнтів)
 *   base = max(base, мінімальний чек типу)
 *   total = ceil(base) + Σ(опції: фіксована ціна або ціна x кількість)
 *
 * Підсумок показується як «від N грн» - фінальну суму підтверджує менеджер.
 */
final class CalcPricer
{
    /**
     * @param ServiceCalcFactor[] $factors обрані коефіцієнти (максимум один на групу)
     * @param array<array{option: CalcOption, qty: int}> $options обрані опції з кількістю
     *
     * @return array{base: int, optionsSum: int, total: int, minApplied: bool}
     */
    public function quote(ServiceCalcType $type, int $area, array $factors, array $options): array
    {
        $base = $area * (float) $type->getPricePerSqm();
        foreach ($factors as $factor) {
            $base *= (float) $factor->getMultiplier();
        }

        $minApplied = false;
        if ($base < (float) $type->getMinPrice()) {
            $base = (float) $type->getMinPrice();
            $minApplied = true;
        }

        $optionsSum = 0;
        foreach ($options as $row) {
            // позиція без числа («За домовленістю») у суму не входить
            $price = (float) ($row['option']->getPrice() ?? '0');
            $optionsSum += (int) ceil($row['option']->isPerUnit() ? $price * $row['qty'] : $price);
        }

        $base = (int) ceil($base);

        return [
            'base' => $base,
            'optionsSum' => $optionsSum,
            'total' => $base + $optionsSum,
            'minApplied' => $minApplied,
        ];
    }
}
