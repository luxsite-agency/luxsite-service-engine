<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

/**
 * Стан секретного налаштування: лише Ready дає відкритий текст. NoKey — на сервері нема SETTINGS_SECRET_KEY (зберегти
 * секрет не можна). Unreadable — конверт у БД є, але ключ інший чи втрачений або дані пошкоджені: секрет вважається
 * відсутнім, в адмінці — «введіть знову». Не 500. Тексти — домен admin: setting.secret_state.<значення>.
 */
enum SecretState: string
{
    case Missing = 'missing';
    case NoKey = 'no_key';
    case Unreadable = 'unreadable';
    case Ready = 'ready';

    public function isReady(): bool
    {
        return self::Ready === $this;
    }
}
