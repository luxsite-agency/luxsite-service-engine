<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServiceTeamMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceTeamMember>
 */
class ServiceTeamMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceTeamMember::class);
    }

    /** @return ServiceTeamMember[] */
    public function findActiveByCity(ServiceCity $city): array
    {
        return $this->findBy(['city' => $city, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
