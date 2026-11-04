<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceLeadAssignment> */
class ServiceLeadAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceLeadAssignment::class);
    }

    public function countToday(ServicePartner $partner, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.partner = :partner')->andWhere('a.assignedAt >= :since')
            ->setParameter('partner', $partner)->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
