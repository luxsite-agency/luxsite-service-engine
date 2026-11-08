<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Кнопка «скопіювати адресу» біля поля з доменом чи хостом (08.11.2026): у списку й на перегляді — шаблон
 * admin/field/copy_url.html.twig (кнопка з data-copy="https://<значення>/"), у формі — атрибут data-copy-url на input,
 * кнопку поруч додає public/assets/admin-copy-url.js (адреса — з поточного значення поля). Одним рядком на будь-яке поле:
 * CopyUrlField::apply(TextField::new('host', 'Хост')).
 */
final class CopyUrlField
{
    public const string TEMPLATE = 'admin/field/copy_url.html.twig';

    public static function apply(TextField $field): TextField
    {
        return $field->setTemplatePath(self::TEMPLATE)
            ->setFormTypeOption('attr', ['data-copy-url' => '1']);
    }

    /** Адреса сайту з домену/хоста: «example.com», «https://example.com/x» → «https://example.com/»; порожньо — null. */
    public static function url(?string $host): ?string
    {
        $host = trim((string) $host);
        if ('' === $host) {
            return null;
        }
        $host = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $host);
        $host = strtolower(rtrim(explode('/', $host, 2)[0], '.'));

        return '' === $host ? null : 'https://' . $host . '/';
    }
}
