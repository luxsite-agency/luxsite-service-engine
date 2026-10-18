<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

/**
 * Доступ до парних *Uk/*Ru полів за локаллю з фолбеком ru→uk
 * (ru-контент частковий; порожнє ru-поле віддає українське значення).
 * Виняток — SEO-поля metaTitle/metaDesc: порожнє ru = null, і шаблон підставляє свій дефолт мовою сторінки;
 * український title/description на /ru/ гірший за згенерований (10.2026, паритет із Joomla-джерелом).
 */
trait LocalizedFieldsTrait
{
    /** Поля без фолбеку ru→uk */
    private const LOC_NO_FALLBACK = ['metaTitle', 'metaDesc'];

    public function loc(string $property, string $locale): mixed
    {
        $suffix = $locale === 'ru' ? 'Ru' : 'Uk';
        $getter = 'get' . ucfirst($property) . $suffix;
        $value = $this->{$getter}();

        if ($suffix === 'Ru' && ($value === null || $value === '' || $value === []) && !\in_array($property, self::LOC_NO_FALLBACK, true)) {
            $getterUk = 'get' . ucfirst($property) . 'Uk';

            return $this->{$getterUk}();
        }

        return $value;
    }

    /** Значення поля саме цієї локалі, без фолбеку ru→uk (для ланцюжка перевизначень: override.ru → base.ru → uk). */
    public function locRaw(string $property, string $locale): mixed
    {
        return $this->{'get' . ucfirst($property) . ($locale === 'ru' ? 'Ru' : 'Uk')}();
    }

    /** Чи є в поля фолбек ru→uk (SEO-поля metaTitle/metaDesc — ні). */
    public function locFallsBack(string $property): bool
    {
        return !\in_array($property, self::LOC_NO_FALLBACK, true);
    }
}
