<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCalcOption;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceCalcOption>
 */
class ServiceCalcOptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCalcOption::class);
    }

    /** @return ServiceCalcOption[] */
    public function findActiveBySite(ServiceSite $site): array
    {
        return $this->findBy(['site' => $site, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }
}
