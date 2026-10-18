<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Ліміт заявок за IP з винятком для своїх адрес.
 *
 * Ліміт лишається жорстким для всіх, а тестування з офісу в нього впиралось:
 * чотири перевірочні заявки поспіль - і форма вже відповідає «забагато».
 * Список винятків - в LEAD_RATE_LIMIT_ALLOWLIST, приймає і окремі адреси,
 * і підмережі (`2a0c:5a82:1102:1300::/64`, `203.0.113.0/24`).
 */
final class LeadRateLimit
{
    public function __construct(
        #[Target('service_lead_ip')]
        private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(env: 'LEAD_RATE_LIMIT_ALLOWLIST')]
        private readonly string $allowlist = '',
    ) {
    }

    public function isAccepted(Request $request): bool
    {
        $ip = $request->getClientIp();

        if ($ip !== null && $this->isAllowlisted($ip)) {
            return true;
        }

        return $this->limiter->create($ip ?? 'unknown')->consume()->isAccepted();
    }

    private function isAllowlisted(string $ip): bool
    {
        $ranges = array_filter(array_map('trim', explode(',', $this->allowlist)));

        return $ranges !== [] && IpUtils::checkIp($ip, $ranges);
    }
}
