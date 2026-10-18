<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServiceWork;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceWork>
 */
class ServiceWorkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceWork::class);
    }

    /**
     * Роботи для сторінки послуги: спершу прив'язані саме до неї, далі -
     * загальні кадри. Фото без прив'язки до міста показуємо всюди: зйомок
     * мало, і краще показати справжню роботу з іншого міста, ніж порожній
     * блок.
     *
     * @return ServiceWork[]
     */
    public function findForItem(ServiceItem $item, ServiceCity $city, int $limit = 6): array
    {
        $rows = $this->createQueryBuilder('w')
            ->where('w.active = true')
            ->andWhere('w.item = :item OR w.item IS NULL')->setParameter('item', $item)
            ->andWhere('w.city = :city OR w.city IS NULL')->setParameter('city', $city)
            // CASE зі зв'язком напряму MySQL не дає порівняти з NULL,
            // тому сортуємо вже в PHP - вибірка тут у межах десятків рядків
            ->orderBy('w.position', 'ASC')->addOrderBy('w.id', 'DESC')
            ->getQuery()->getResult();

        usort($rows, static function (ServiceWork $a, ServiceWork $b) use ($item, $city): int {
            $rank = static fn (ServiceWork $w): int => ($w->getItem() === $item ? 0 : 2)
                + ($w->getCity() === $city ? 0 : 1);

            return $rank($a) <=> $rank($b);
        });

        return \array_slice($rows, 0, $limit);
    }

    /** Наскрізна галерея міста: усе, що не прив'язане до чужого міста.
     *
     * @return ServiceWork[]
     */
    public function findForCity(ServiceCity $city, int $limit = 12): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.active = true')
            ->andWhere('w.city = :city OR w.city IS NULL')->setParameter('city', $city)
            ->orderBy('w.position', 'ASC')->addOrderBy('w.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
