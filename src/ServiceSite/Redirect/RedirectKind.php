<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

/**
 * Звідки правило: заведене вручну (адмінка, імпорт CSV, API), записане рушієм при зміні slug (авто-301) чи перенесене
 * з паку (app.legacy_redirects / app.gone_prefixes, дія «Перенести з паку»).
 */
enum RedirectKind: string
{
    case Manual = 'manual';
    case Auto = 'auto';
    case Pack = 'pack';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'вручну',
            self::Auto => 'авто',
            self::Pack => 'з паку',
        };
    }
}
