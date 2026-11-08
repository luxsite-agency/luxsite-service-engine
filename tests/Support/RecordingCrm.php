<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ServiceLead;
use App\ServiceSite\Crm\LeadCrmCapabilities;
use App\ServiceSite\Crm\LeadCrmConnector;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CRM-конектор для тестів (LeadSpamKernel, app.crm.system = recording): запам'ятовує, які заявки йому віддали, і
 * позначає їх як відправлені — так тест бачить, чи пішла заявка в CRM.
 */
final class RecordingCrm implements LeadCrmConnector
{
    public const SYSTEM = 'recording';

    /** @var list<int> id заявок, переданих у push() */
    public static array $pushed = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function system(): string
    {
        return self::SYSTEM;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function push(ServiceLead $lead): bool
    {
        self::$pushed[] = (int) $lead->getId();
        $lead->setCrmExternalId('rec-' . $lead->getId())->setCrmSystem(self::SYSTEM)->setCrmSyncedAt(new \DateTimeImmutable())->setCrmError(null);
        $this->em->flush();

        return true;
    }

    public function capabilities(): LeadCrmCapabilities
    {
        return new LeadCrmCapabilities(pushLead: true);
    }
}
