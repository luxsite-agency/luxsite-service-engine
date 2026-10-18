<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

/**
 * Позиція, яку калькулятор може додати до розрахунку.
 *
 * Потрібен, щоб калькулятор не залежав від конкретної таблиці: ціни живуть
 * у прайсі (ServicePrice), бо ту саму позицію видно і в таблиці на сторінці
 * послуги. Стара ServiceCalcOption лишається сумісною на час переходу.
 */
interface CalcOption
{
    public function getId(): ?int;

    /** Ціна в грн; null - позиція без числа («За домовленістю»), калькулятор її пропускає */
    public function getPrice(): ?string;

    /** true - рахується за кількість (м², шт), false - разова сума */
    public function isPerUnit(): bool;

    /** Стеля кількості в полі вводу */
    public function getMaxQty(): int;

    /**
     * Локалізоване поле: loc('name', 'uk'), loc('unit', 'ru').
     * Тип mixed - такий у LocalizedFieldsTrait, звузити не можна.
     */
    public function loc(string $property, string $locale): mixed;
}
