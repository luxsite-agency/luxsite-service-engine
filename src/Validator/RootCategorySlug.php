<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Рубрика бренду з рубриками від кореня (app.brands.<slug>.category_url: root) не може мати slug фіксованого розділу
 * (about, contacts, розділ статей бренду…): адреса /<slug>/ належала б розділу, рубрика була б недосяжна.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RootCategorySlug extends Constraint
{
    public string $message = '«{{ slug }}» — адреса фіксованого розділу сайту; рубрики цього бренду живуть від кореня (/{{ slug }}/), оберіть інший slug.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
