<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceItem>
 */
class ServiceItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceItem::class);
    }

    /**
     * @param ServiceCity|null $city місто: без послуг, що в ньому не надаються (ServiceItem.hiddenCities)
     *
     * @return ServiceItem[]
     */
    public function findActiveByCategory(ServiceCategory $category, ?ServiceCity $city = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.category = :cat')->setParameter('cat', $category)
            ->andWhere('i.active = true')
            ->orderBy('i.position', 'ASC')
            ->addOrderBy('i.id', 'ASC');
        if ($city !== null) {
            $qb->andWhere(':city NOT MEMBER OF i.hiddenCities')->setParameter('city', $city);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Всі послуги сайту з категоріями (головна, каталог, sitemap, райони, футер).
     *
     * @param ServiceCity|null $city місто: без послуг, що в ньому не надаються (ServiceItem.hiddenCities)
     *
     * @return ServiceItem[]
     */
    public function findActiveBySite(ServiceSite $site, ?ServiceCity $city = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->addSelect('c')
            ->join('i.category', 'c')
            ->where('i.site = :site')->setParameter('site', $site)
            ->andWhere('i.active = true')
            ->andWhere('c.active = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('i.position', 'ASC')
            ->addOrderBy('i.id', 'ASC');
        if ($city !== null) {
            $qb->andWhere(':city NOT MEMBER OF i.hiddenCities')->setParameter('city', $city);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Пошук по слагу колонки поточної локалі в межах категорії.
     * Для ru порожній slugRu = «використовується uk-слаг» (див. ServiceCategoryRepository).
     */
    public function findOneActiveBySlug(ServiceCategory $category, string $slug, string $locale): ?ServiceItem
    {
        if ($locale === 'ru') {
            return $this->findOneBy(['category' => $category, 'slugRu' => $slug, 'active' => true])
                ?? $this->findOneBy(['category' => $category, 'slugRu' => null, 'slugUk' => $slug, 'active' => true]);
        }

        return $this->findOneBy(['category' => $category, 'slugUk' => $slug, 'active' => true]);
    }

    /**
     * Пошук по слагу в межах усього бренду: у пласкій схемі URL категорії в адресі немає.
     * Для ru порожній slugRu = «використовується uk-слаг».
     */
    public function findOneActiveBySiteAndSlug(ServiceSite $site, string $slug, string $locale): ?ServiceItem
    {
        if ($locale === 'ru') {
            return $this->findOneBy(['site' => $site, 'slugRu' => $slug, 'active' => true])
                ?? $this->findOneBy(['site' => $site, 'slugRu' => null, 'slugUk' => $slug, 'active' => true]);
        }

        return $this->findOneBy(['site' => $site, 'slugUk' => $slug, 'active' => true]);
    }

    public function findOneBySiteAndLegacyId(ServiceSite $site, int $legacyId): ?ServiceItem
    {
        return $this->findOneBy(['site' => $site, 'legacyId' => $legacyId]);
    }
}
