<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceSite>
 */
class ServiceSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceSite::class);
    }

    public function findActiveBySlug(string $slug): ?ServiceSite
    {
        return $this->findOneBy(['slug' => $slug, 'active' => true]);
    }

    /** @return ServiceSite[] */
    public function findAllActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC']);
    }
}
