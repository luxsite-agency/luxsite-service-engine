<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\ServiceSite\Partners\HostResolver;

final class PartnerHostResolver extends HostResolver
{
    public function ips(string $host): array
    {
        return ['93.184.216.34'];
    }
}
