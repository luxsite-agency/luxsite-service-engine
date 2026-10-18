<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCategory;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceCategory>
 */
class ServiceCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCategory::class);
    }

    /** @return ServiceCategory[] */
    public function findActiveBySite(ServiceSite $site): array
    {
        return $this->findBy(['site' => $site, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Пошук по слагу колонки поточної локалі (per-мовні слаги).
     * Для ru порожній slugRu означає «використовується uk-слаг» (так генерує
     * URL LocalizedFieldsTrait::loc і обіцяє адмінка) — тому другий крок
     * шукає uk-слаг серед записів без ru-слага, інакше ru-посилання були б 404.
     */
    public function findOneActiveBySlug(ServiceSite $site, string $slug, string $locale): ?ServiceCategory
    {
        if ($locale === 'ru') {
            return $this->findOneBy(['site' => $site, 'slugRu' => $slug, 'active' => true])
                ?? $this->findOneBy(['site' => $site, 'slugRu' => null, 'slugUk' => $slug, 'active' => true]);
        }

        return $this->findOneBy(['site' => $site, 'slugUk' => $slug, 'active' => true]);
    }

    public function findOneBySiteAndLegacyId(ServiceSite $site, int $legacyId): ?ServiceCategory
    {
        return $this->findOneBy(['site' => $site, 'legacyId' => $legacyId]);
    }
}
