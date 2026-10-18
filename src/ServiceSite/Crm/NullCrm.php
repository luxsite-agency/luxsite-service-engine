<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Crm;

use App\Entity\ServiceLead;

/** Сайт без CRM: заявка лишається в БД і йде листом, назовні нічого не шлемо. */
final class NullCrm implements LeadCrmConnector
{
    public const SYSTEM = 'none';

    public function system(): string
    {
        return self::SYSTEM;
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function push(ServiceLead $lead): bool
    {
        return false;
    }

    public function capabilities(): LeadCrmCapabilities
    {
        return new LeadCrmCapabilities();
    }
}
