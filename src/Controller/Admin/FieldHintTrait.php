<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;

/**
 * Кругла підказка біля назви колонки у списку.
 *
 * EasyAdmin 5.5 показує setHelp() лише у формах, а в таблиці назви колонок
 * лишаються голими - «Множник», «Передобраний». Для того, хто заходить раз на
 * місяць, це нічого не означає.
 *
 * ⛔ Не вкладати HTML у саму мітку: цей же рядок іде в `data-label` кожної
 * комірки, а на вузьких екранах EasyAdmin виводить його як текст через
 * `content: attr(data-label)` - користувач побачив би сиру розмітку.
 *
 * Тому текст підказки їде в `data-hint` на комірках, а `public/assets/admin-hints.js`
 * переносить його в заголовок колонки і вішає бутстрапівський тултіп.
 */
trait FieldHintTrait
{
    /**
     * Атрибути комірки з текстом підказки. Для випадків, коли в списку
     * доречне інше формулювання, ніж під полем у формі.
     *
     * @return array<string, string>
     */
    private function hint(string $text): array
    {
        return ['data-hint' => $text];
    }

    /**
     * У списку першою має стояти назва, а не службова прив'язка: очима шукають
     * «Прибирання квартир», а не «Прибирання квартир і будинків». Константа
     * INDEX_PRIMARY кожного контролера каже, яке поле винести наперед.
     *
     * Чіпаємо тільки список: у формі порядок задає розкладка по вкладках і
     * блоках, там перестановка все б зламала.
     */
    private function primaryFirst(iterable $fields, string $pageName): iterable
    {
        $primary = \defined(static::class . '::INDEX_PRIMARY') ? static::INDEX_PRIMARY : null;
        if ($pageName !== 'index' || $primary === null) {
            yield from $fields;

            return;
        }

        $all = is_array($fields) ? $fields : iterator_to_array($fields, false);
        $found = null;
        foreach ($all as $i => $field) {
            if ($field instanceof FieldInterface && $field->getAsDto()->getProperty() === $primary) {
                $found = $i;
                break;
            }
        }
        if ($found === null) {
            yield from $all;

            return;
        }

        // одразу за id, якщо він є першим - інакше найпершою колонкою
        $insertAt = ($all[0] ?? null) instanceof FieldInterface
            && $all[0]->getAsDto()->getProperty() === 'id' ? 1 : 0;

        $moved = $all[$found];
        unset($all[$found]);
        $all = array_values($all);
        array_splice($all, $insertAt, 0, [$moved]);

        yield from $all;
    }

    /**
     * Проганяє поля і підставляє підказку з їхнього ж setHelp(), щоб не писати
     * один текст двічі. Те, що вже має власний data-hint, лишається як є.
     */
    private function withHints(iterable $fields): iterable
    {
        foreach ($fields as $field) {
            if ($field instanceof FieldInterface) {
                $dto = $field->getAsDto();
                $attributes = $dto->getHtmlAttributes();
                $help = $dto->getHelp();

                if (!isset($attributes['data-hint']) && \is_string($help) && trim($help) !== '') {
                    $attributes['data-hint'] = trim(strip_tags($help));
                    $field->setHtmlAttributes($attributes);
                }
            }

            yield $field;
        }
    }
}
