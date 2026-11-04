<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServicePartner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServicePartner> */
class ServicePartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServicePartner::class);
    }

    /** @return list<ServicePartner> */
    public function activeForCity(ServiceCity $city): array
    {
        return $this->findBy(['city' => $city, 'status' => ServicePartner::STATUS_ACTIVE]);
    }

    /** Ручна передача допускає призупинених партнерів, але не тих, хто пішов. @return list<ServicePartner> */
    public function manualForCity(ServiceCity $city): array
    {
        return $this->findBy(['city' => $city, 'status' => [ServicePartner::STATUS_ACTIVE, ServicePartner::STATUS_PAUSED]], ['name' => 'ASC', 'id' => 'ASC']);
    }
}
