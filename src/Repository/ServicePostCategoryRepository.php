<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServicePostCategory;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServicePostCategory> */
class ServicePostCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServicePostCategory::class);
    }

    /**
     * Активні рубрики сайту, що існують мовою (слаг і назва), у порядку позиції.
     *
     * @return list<ServicePostCategory>
     */
    public function findActiveBySite(ServiceSite $site, string $locale): array
    {
        $all = $this->findBy(['site' => $site, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);

        return array_values(array_filter($all, static fn (ServicePostCategory $c): bool => $c->existsIn($locale)));
    }

    public function findOneActiveBySlug(ServiceSite $site, string $slug, string $locale): ?ServicePostCategory
    {
        foreach ($this->findActiveBySite($site, $locale) as $c) {
            if ($c->slug($locale) === $slug) {
                return $c;
            }
        }

        return null;
    }
}
