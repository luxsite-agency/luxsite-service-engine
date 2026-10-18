<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServiceItemCity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceItemCity>
 */
class ServiceItemCityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceItemCity::class);
    }

    /** Переписаний під місто текст послуги; null - беремо загальний. */
    public function findOneByItemAndCity(ServiceItem $item, ServiceCity $city): ?ServiceItemCity
    {
        return $this->findOneBy(['item' => $item, 'city' => $city]);
    }

    /**
     * Усі перевизначення міста, ключ — id послуги (картки в списках: /services/, категорія, головна, «інші послуги»).
     *
     * @return array<int, ServiceItemCity>
     */
    public function findByCityIndexed(ServiceCity $city): array
    {
        $map = [];
        foreach ($this->findBy(['city' => $city]) as $row) {
            $map[$row->getItem()->getId()] = $row;
        }

        return $map;
    }

    /**
     * Дата зміни тексту під місто, ключ - id послуги. Для lastmod у карті
     * сайту: тягнути сутність цілком на кожну з 43 послуг немає сенсу.
     *
     * @return array<int, \DateTimeInterface>
     */
    public function updatedAtByItem(ServiceCity $city): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.item) AS itemId', 't.updatedAt', 't.createdAt')
            ->where('t.city = :city')->setParameter('city', $city)
            ->getQuery()->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $date = $row['updatedAt'] ?? $row['createdAt'];
            if ($date !== null) {
                $map[(int) $row['itemId']] = $date;
            }
        }

        return $map;
    }
}
