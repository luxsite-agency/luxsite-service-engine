<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCategory;
use App\Entity\ServiceCategoryCity;
use App\Entity\ServiceCity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceCategoryCity> */
class ServiceCategoryCityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCategoryCity::class);
    }

    public function findOneByCategoryAndCity(ServiceCategory $category, ServiceCity $city): ?ServiceCategoryCity
    {
        return $this->findOneBy(['category' => $category, 'city' => $city]);
    }
}
