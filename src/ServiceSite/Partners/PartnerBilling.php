<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Partners;

use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\Repository\ServiceLeadAssignmentRepository;

final class PartnerBilling
{
    public function __construct(private readonly PartnersConfig $config, private readonly ServiceLeadAssignmentRepository $assignments)
    {
    }

    /** @return array{month: string, start: \DateTimeImmutable, end: \DateTimeImmutable} */
    public function period(?string $month): array
    {
        $now = new \DateTimeImmutable('now', $this->config->timezone);
        if ($month === null || preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month) !== 1) {
            $month = $now->format('Y-m');
        }
        $start = new \DateTimeImmutable($month . '-01 00:00:00', $this->config->timezone);
        // Doctrine зберігає дати без поясу, як LeadRouter; межі місяця спочатку в поясі паку (включно з DST).
        $storage = new \DateTimeZone(date_default_timezone_get());

        return ['month' => $month, 'start' => $start->setTimezone($storage), 'end' => $start->modify('+1 month')->setTimezone($storage)];
    }

    /** @return array{month: string, count: int, total: int, statuses: array<string, array{count: int, total: int}>, rows: list<array<string, mixed>>} */
    public function report(ServicePartner $partner, ?string $month): array
    {
        $period = $this->period($month);
        $assignments = $this->assignments->createQueryBuilder('a')->addSelect('lead')->join('a.lead', 'lead')
            ->andWhere('a.partner = :partner')->andWhere('a.assignedAt >= :start AND a.assignedAt < :end')
            ->setParameter('partner', $partner)->setParameter('start', $period['start'])->setParameter('end', $period['end'])
            ->orderBy('a.assignedAt', 'DESC')->addOrderBy('a.id', 'DESC')->getQuery()->getResult();
        $rows = [];
        $total = 0;
        $statuses = array_fill_keys(ServiceLeadAssignment::STATUSES, ['count' => 0, 'total' => 0]);
        foreach ($assignments as $assignment) {
            $status = $assignment->getStatus();
            $billed = !\in_array($status, $this->config->billingExcludes, true);
            $price = $assignment->getPrice() ?? 0;
            $amount = $billed ? $price : 0;
            $total += $amount;
            ++$statuses[$status]['count'];
            $statuses[$status]['total'] += $amount;
            // Проєкція однакова для HTML і CSV: жодних імен, телефонів чи інших партнерів.
            $rows[] = ['assignment_id' => $assignment->getId(),
                'assigned_at' => $assignment->getAssignedAt()->setTimezone($this->config->timezone)->format('Y-m-d H:i:s P'),
                'zip_from' => $assignment->getLead()?->getZipFrom(), 'zip_to' => $assignment->getLead()?->getZipTo(),
                'status' => $status, 'price_cents' => $price, 'billed' => $billed ? 'yes' : 'no'];
        }

        return ['month' => $period['month'], 'count' => \count($rows), 'total' => $total, 'statuses' => $statuses, 'rows' => $rows];
    }
}
