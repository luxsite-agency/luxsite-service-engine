<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use App\Entity\AdminApiToken;

/** Результат видачі: сутність і значення, яке показується один раз. */
final readonly class IssuedToken
{
    public function __construct(public AdminApiToken $token, public string $plain)
    {
    }
}
