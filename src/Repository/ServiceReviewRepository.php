<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServiceReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceReview>
 */
class ServiceReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceReview::class);
    }

    /** @return ServiceReview[] */
    public function findActiveByCity(ServiceCity $city, int $limit = 30): array
    {
        return $this->findBy(['city' => $city, 'active' => true], ['reviewedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** @return array{count: int, avg: float}|null Для AggregateRating */
    public function aggregateByCity(ServiceCity $city): ?array
    {
        $row = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS cnt', 'AVG(r.stars) AS avg')
            ->where('r.city = :city')->setParameter('city', $city)
            ->andWhere('r.active = true')
            ->getQuery()->getSingleResult();

        return ((int) $row['cnt']) > 0 ? ['count' => (int) $row['cnt'], 'avg' => round((float) $row['avg'], 1)] : null;
    }
}
