<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServicePage;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServicePage>
 */
class ServicePageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServicePage::class);
    }

    /**
     * Активна сторінка за шляхом адреси без слешів по краях («vakansii», «vakansii/likar») у мові $locale: останній
     * сегмент — слаг сторінки, перший (якщо є) — слаг її батька; батько теж активний. Для ru порожній slugRu =
     * «використовується uk-слаг» (як у послуг). Місто тут не перевіряється — це робить контролер (404).
     */
    public function findOneActiveByPath(ServiceSite $site, string $path, string $locale): ?ServicePage
    {
        $segments = explode('/', trim($path, '/'));
        if (\count($segments) > 2 || \in_array('', $segments, true)) {
            return null;
        }
        $slug = (string) end($segments);
        $condition = $locale === 'ru'
            ? '(p.slugRu = :slug OR (p.slugRu IS NULL AND p.slugUk = :slug))'
            : 'p.slugUk = :slug';
        /** @var list<ServicePage> $candidates */
        $candidates = $this->createQueryBuilder('p')
            ->addSelect('parent')
            ->leftJoin('p.parent', 'parent')
            ->where('p.site = :site')->setParameter('site', $site)
            ->andWhere($condition)->setParameter('slug', $slug)
            ->andWhere('p.active = true')
            ->getQuery()->getResult();
        foreach ($candidates as $page) {
            if ($page->isLive() && $page->path($locale) === implode('/', $segments)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Дочірні сторінки для списку на сторінці батька: активні й видимі в місті.
     *
     * @return list<ServicePage>
     */
    public function findLiveChildren(ServicePage $parent, ServiceCity $city): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.parent = :parent')->setParameter('parent', $parent)
            ->andWhere('p.active = true')
            ->andWhere(':city NOT MEMBER OF p.hiddenCities')->setParameter('city', $city)
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.id', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Усі сторінки сайту, видимі в місті (sitemap, футер): активні, батько активний і теж видимий у місті.
     *
     * @return list<ServicePage>
     */
    public function findLiveBySite(ServiceSite $site, ServiceCity $city): array
    {
        /** @var list<ServicePage> $pages */
        $pages = $this->createQueryBuilder('p')
            ->addSelect('parent')
            ->leftJoin('p.parent', 'parent')
            ->where('p.site = :site')->setParameter('site', $site)
            ->andWhere('p.active = true')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();

        return array_values(array_filter($pages, static fn (ServicePage $p): bool => $p->isLive() && $p->isAvailableIn($city)));
    }
}
