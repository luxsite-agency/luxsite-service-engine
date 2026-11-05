<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\ServiceLeadAssignmentRepository;
use App\ServiceSite\Partners\PartnerBilling;
use App\ServiceSite\Partners\PartnersConfig;
use PHPUnit\Framework\TestCase;

final class PartnerBillingPeriodTest extends TestCase
{
    public function testDstMonthAndInvalidMonthFallback(): void
    {
        $billing = new PartnerBilling(new PartnersConfig([], 'America/New_York'), $this->createStub(ServiceLeadAssignmentRepository::class));
        $period = $billing->period('2026-11');
        self::assertSame('2026-11-01T04:00:00+00:00', $period['start']->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('2026-12-01T05:00:00+00:00', $period['end']->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        $current = (new \DateTimeImmutable('now', new \DateTimeZone('America/New_York')))->format('Y-m');
        foreach (['garbage', '', '2026-13', '2026-00', '2026-1', '0000-01', "2026-11\n"] as $input) {
            self::assertSame($current, $billing->period($input)['month']);
        }
    }
}
