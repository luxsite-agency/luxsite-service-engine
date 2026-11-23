<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Form\HtmlSourceType;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

/**
 * Поле з HTML для сайту (описи, тексти міста, «Про нас», юридичні сторінки): textarea з кодом як є.
 *
 * Не TextEditorField: Trix у браузері переписує весь HTML поля при відкритті форми (h2/h3, класи, table, ol
 * стають div, картинки — figure data-trix-attachment) і зберігає це, навіть якщо поле не чіпали
 * (glemanlas 23.11.2026, /srv/docs/gotchas.md). Шаблони паку розраховані на свою розмітку, тож редагуємо код.
 */
final class HtmlField
{
    public static function new(string $propertyName, ?string $label = null): TextareaField
    {
        return TextareaField::new($propertyName, $label)
            ->setFormType(HtmlSourceType::class)
            ->setNumOfRows(12)
            ->setFormTypeOption('attr.class', 'font-monospace')
            ->setFormTypeOption('attr.spellcheck', 'false');
    }
}
