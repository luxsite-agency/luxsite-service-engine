<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Partners;

/** Окремий сервіс, щоб тести вебхуків не використовували DNS. */
class HostResolver
{
    /** @return list<string> */
    public function ips(string $host): array
    {
        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        foreach (@dns_get_record($host, \DNS_A | \DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $ips[] = $record['ip'] ?? $record['ipv6'];
            }
        }
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
