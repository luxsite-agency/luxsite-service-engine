<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServiceSite;
use App\Entity\ServiceTeamMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceTeamMember>
 */
class ServiceTeamMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceTeamMember::class);
    }

    /**
     * Активна команда міста: основне місто людини або одне з інших (лікар у двох філіях).
     * Без записів у service_team_member_city — те саме, що раніше findBy(['city' => $city]).
     *
     * @return ServiceTeamMember[]
     */
    public function findActiveByCity(ServiceCity $city): array
    {
        return $this->activeInCity($city)->getQuery()->getResult();
    }

    /**
     * Люди міста, що роблять послугу (блок «Фахівці» на сторінці послуги).
     *
     * @return ServiceTeamMember[]
     */
    public function findActiveByItemAndCity(ServiceItem $item, ServiceCity $city): array
    {
        return $this->activeInCity($city)
            ->andWhere(':item MEMBER OF m.items')->setParameter('item', $item)
            ->getQuery()->getResult();
    }

    /**
     * Людина зі сторінкою за слагом мови сторінки в межах сайту (усі міста сайту). Для ru порожній slugRu = uk-слаг.
     * Місто тут не фільтрується: контролер сам вирішує, показати, переслати на основне місто чи 404.
     */
    public function findOneActiveBySiteAndSlug(ServiceSite $site, string $slug, string $locale): ?ServiceTeamMember
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.city', 'pc')
            ->where('pc.site = :site')->setParameter('site', $site)
            ->andWhere('m.active = true')
            ->setMaxResults(1);
        if ($locale === 'ru') {
            $hit = (clone $qb)->andWhere('m.slugRu = :slug')->setParameter('slug', $slug)->getQuery()->getOneOrNullResult();

            return $hit ?? $qb->andWhere('m.slugRu IS NULL AND m.slugUk = :slug')->setParameter('slug', $slug)->getQuery()->getOneOrNullResult();
        }

        return $qb->andWhere('m.slugUk = :slug')->setParameter('slug', $slug)->getQuery()->getOneOrNullResult();
    }

    /** Будь-хто (і неактивний) з таким слагом uk чи ru на сайті, крім $exceptId — для перевірки унікальності слагу */
    public function findOneBySiteAndAnySlug(ServiceSite $site, string $slug, ?int $exceptId = null): ?ServiceTeamMember
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.city', 'pc')
            ->where('pc.site = :site')->setParameter('site', $site)
            ->andWhere('m.slugUk = :slug OR m.slugRu = :slug')->setParameter('slug', $slug)
            ->setMaxResults(1);
        if ($exceptId !== null) {
            $qb->andWhere('m.id <> :id')->setParameter('id', $exceptId);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Сторінки людей, канонічні на хості міста (основне місто = це), — для sitemap.xml цього хоста.
     *
     * @return ServiceTeamMember[]
     */
    public function findActivePagesByPrimaryCity(ServiceCity $city): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.city = :city')->setParameter('city', $city)
            ->andWhere('m.active = true')
            ->andWhere('m.slugUk IS NOT NULL')
            ->orderBy('m.position', 'ASC')->addOrderBy('m.id', 'ASC')
            ->getQuery()->getResult();
    }

    private function activeInCity(ServiceCity $city): QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->where('m.city = :city OR :city MEMBER OF m.cities')->setParameter('city', $city)
            ->andWhere('m.active = true')
            ->orderBy('m.position', 'ASC')->addOrderBy('m.id', 'ASC');
    }
}
