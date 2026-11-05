<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

/**
 * Доступ до полів *Uk/*Ru/*En за локаллю з фолбеком ru→uk
 * (ru-контент частковий; порожнє ru-поле віддає українське значення).
 * Виняток — SEO-поля metaTitle/metaDesc: порожнє ru = null, і шаблон підставляє свій дефолт мовою сторінки;
 * український title/description на /ru/ гірший за згенерований (10.2026, паритет із Joomla-джерелом).
 * en (22.10.2026) — без фолбеку: англійська сторінка не показує український текст; сутність без колонки *En
 * віддає null. Порожній англійський слаг каталогу бере uk-слаг лише для адреси; статті вимагають власного en-слага.
 */
trait LocalizedFieldsTrait
{
    /** Поля без фолбеку ru→uk */
    private const LOC_NO_FALLBACK = ['metaTitle', 'metaDesc'];
    /** Суфікс властивості за мовою; невідома мова — як uk (так було до en) */
    private const LOC_SUFFIX = ['uk' => 'Uk', 'ru' => 'Ru', 'en' => 'En'];

    public function loc(string $property, string $locale): mixed
    {
        $value = $this->locRaw($property, $locale);

        $empty = $value === null || $value === '' || $value === [];
        // слаг — адреса, а не текст: порожній *En бере українську (латинську) частину
        if ($empty && $property === 'slug' && $locale === 'en' && $this->englishSlugFallsBack()) {
            return $this->locRaw($property, 'uk');
        }
        if ($locale === 'ru' && $empty && $this->locFallsBack($property)) {
            return $this->locRaw($property, 'uk');
        }

        return $value;
    }

    /** Catalog addresses may reuse uk; posts override this because an en post requires its own slug. */
    protected function englishSlugFallsBack(): bool
    {
        return true;
    }

    /** Значення поля саме цієї локалі, без фолбеку ru→uk (для ланцюжка перевизначень: override.ru → base.ru → uk). */
    public function locRaw(string $property, string $locale): mixed
    {
        $getter = 'get' . ucfirst($property) . self::locSuffix($locale);

        return method_exists($this, $getter) ? $this->{$getter}() : null;
    }

    /** Чи є в поля фолбек ru→uk (SEO-поля metaTitle/metaDesc — ні). */
    public function locFallsBack(string $property): bool
    {
        return !\in_array($property, self::LOC_NO_FALLBACK, true);
    }

    private static function locSuffix(string $locale): string
    {
        return self::LOC_SUFFIX[$locale] ?? 'Uk';
    }
}
