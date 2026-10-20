<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCategory;
use App\Entity\ServiceItem;
use App\Entity\ServicePage;
use App\Entity\ServicePost;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Додаткові поля записів, які описує пак (app.custom_fields), без нових колонок і коду:
 *
 *   app.custom_fields:
 *       item:                                   # тип запису: item (послуга), post (стаття), category (розділ), page (сторінка)
 *           - key: tonnage                      # [a-z0-9_]; значення — ExtraFieldsTrait::$extra[key][uk|ru]
 *             label: { uk: Вантажопідйомність, ru: Грузоподъёмность }
 *             suffix: { uk: ' т', ru: ' т' }    # дописується при показі
 *             localized: true                   # true = окремі значення uk/ru в адмінці; false = одне (ru бере його ж)
 *             filter: false                     # true = поле для фільтра/групування списку (тема бере його значення)
 *             categories: [vehicle-type-transportation]   # лише для записів цих розділів (slug_uk); порожньо = усі
 *
 * Шаблони: custom_fields(entity, locale) → [{key, label, value, filter}] заповнених полів (Twig, ServiceSiteExtension),
 * entity.extraValue(key, locale) — сире значення. Адмінка: ExtraFieldsType (поля з'являються самі).
 */
final class CustomFields
{
    /** @var array<string, list<array{key: string, label: array<string, string>, suffix: array<string, string>, localized: bool, filter: bool, categories: list<string>}>> */
    private readonly array $defs;

    /** @param array<string, mixed> $config app.custom_fields */
    public function __construct(
        #[Autowire('%app.custom_fields%')]
        array $config = [],
    ) {
        $defs = [];
        foreach ($config as $type => $fields) {
            foreach ((array) $fields as $f) {
                $key = (string) ($f['key'] ?? '');
                if (!preg_match('/^[a-z0-9_]{1,40}$/', $key)) {
                    throw new \InvalidArgumentException(sprintf('app.custom_fields.%s: ключ поля «%s» — лише [a-z0-9_], до 40 символів.', $type, $key));
                }
                $label = (array) ($f['label'] ?? []);
                $suffix = (array) ($f['suffix'] ?? []);
                $defs[(string) $type][] = [
                    'key' => $key,
                    'label' => ['uk' => (string) ($label['uk'] ?? $key), 'ru' => (string) ($label['ru'] ?? $label['uk'] ?? $key)],
                    'suffix' => ['uk' => (string) ($suffix['uk'] ?? ''), 'ru' => (string) ($suffix['ru'] ?? $suffix['uk'] ?? '')],
                    'localized' => (bool) ($f['localized'] ?? false),
                    'filter' => (bool) ($f['filter'] ?? false),
                    'categories' => array_values(array_map('strval', (array) ($f['categories'] ?? []))),
                ];
            }
        }
        $this->defs = $defs;
    }

    /** Тип запису для сутності: item | post | category | page; null — сутність додаткових полів не має. */
    public static function typeOf(object $entity): ?string
    {
        return match (true) {
            $entity instanceof ServiceItem => 'item',
            $entity instanceof ServicePost => 'post',
            $entity instanceof ServiceCategory => 'category',
            $entity instanceof ServicePage => 'page',
            default => null,
        };
    }

    /**
     * Описи полів типу; з $categorySlug — лише ті, що стосуються цього розділу (або всіх).
     *
     * @return list<array{key: string, label: array<string, string>, suffix: array<string, string>, localized: bool, filter: bool, categories: list<string>}>
     */
    public function definitions(string $type, ?string $categorySlug = null): array
    {
        $out = [];
        foreach ($this->defs[$type] ?? [] as $def) {
            if ($categorySlug === null || $def['categories'] === [] || \in_array($categorySlug, $def['categories'], true)) {
                $out[] = $def;
            }
        }

        return $out;
    }

    public function has(string $type): bool
    {
        return ($this->defs[$type] ?? []) !== [];
    }

    /**
     * Заповнені поля запису для показу, у порядку з паку.
     *
     * @return list<array{key: string, label: string, value: string, raw: string, filter: bool}>
     */
    public function display(object $entity, string $locale): array
    {
        $type = self::typeOf($entity);
        if ($type === null || !method_exists($entity, 'extraValue')) {
            return [];
        }
        $lang = $locale === 'ru' ? 'ru' : 'uk';
        $cat = $entity instanceof ServiceItem ? $entity->getCategory()?->getSlugUk() : ($entity instanceof ServiceCategory ? $entity->getSlugUk() : null);
        $out = [];
        foreach ($this->definitions($type, $cat) as $def) {
            $raw = $entity->extraValue($def['key'], $lang);
            if ($raw === null) {
                continue;
            }
            $out[] = ['key' => $def['key'], 'label' => $def['label'][$lang], 'value' => $raw . $def['suffix'][$lang], 'raw' => $raw, 'filter' => $def['filter']];
        }

        return $out;
    }
}
