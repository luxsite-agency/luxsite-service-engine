<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceAuthor;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServiceAuthor> */
class ServiceAuthorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceAuthor::class);
    }

    /** @return list<ServiceAuthor> */
    public function findActiveBySite(ServiceSite $site): array
    {
        return $this->findBy(['site' => $site, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneActiveBySlug(ServiceSite $site, string $slug): ?ServiceAuthor
    {
        return $this->findOneBy(['site' => $site, 'slug' => $slug, 'active' => true]);
    }
}
