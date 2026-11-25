<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** English-only deployments may supply the corresponding En field instead of Uk. */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class CatalogNotBlank extends Constraint
{
    public function __construct(
        public string $message = 'This value should not be blank.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(groups: $groups, payload: $payload);
    }
}
