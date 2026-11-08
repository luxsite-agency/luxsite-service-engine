<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Налаштування seo.hreflang_codes: мова рядка «uk: uk-UA» має бути з мов розгортання (app.locales). Формат рядків і кодів
 * перевіряє SiteSettingKeys (статично); тут — те, що залежить від розгортання.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class HreflangLocales extends Constraint
{
    public string $message = 'setting.hreflang_unknown_locale';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
