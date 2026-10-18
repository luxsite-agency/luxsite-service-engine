<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceCity>
 */
class ServiceCityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCity::class);
    }

    public function findActiveByHost(string $host): ?ServiceCity
    {
        return $this->findOneBy(['host' => $host, 'active' => true]);
    }

    public function findActiveApex(ServiceSite $site): ?ServiceCity
    {
        // сортування — детермінізм, якщо помилково позначено два апекси
        return $this->findOneBy(['site' => $site, 'isApex' => true, 'active' => true], ['id' => 'ASC']);
    }

    public function findActiveBySiteAndSlug(ServiceSite $site, string $slug): ?ServiceCity
    {
        return $this->findOneBy(['site' => $site, 'slug' => $slug, 'active' => true]);
    }

    /** @return ServiceCity[] Апекс першим — для списків міст у футері/перемикачі */
    public function findAllActiveBySite(ServiceSite $site): array
    {
        return $this->findBy(['site' => $site, 'active' => true], ['isApex' => 'DESC', 'nameNomUk' => 'ASC']);
    }
}
