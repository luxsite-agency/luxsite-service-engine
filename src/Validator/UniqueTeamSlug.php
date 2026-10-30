<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Слаг людини команди унікальний у межах сайту-бренду (усі міста сайту ділять одну адресу /<префікс>/<слаг>/).
 * Колонки site у service_team_member нема (сайт — через місто), тому UniqueEntity не підходить.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class UniqueTeamSlug extends Constraint
{
    public string $message = 'Слаг «{{ slug }}» уже має інша людина команди цього сайту ({{ name }}).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
