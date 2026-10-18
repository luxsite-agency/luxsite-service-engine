<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServicePrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServicePrice>
 */
class ServicePriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServicePrice::class);
    }

    /** @return ServicePrice[] */
    public function findActiveByItemAndCity(ServiceItem $item, ServiceCity $city): array
    {
        return $this->findBy(['item' => $item, 'city' => $city, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Додаткові послуги калькулятора цього міста. Беремо з прайсу, щоб ціна
     * в калькуляторі й ціна в таблиці на сторінці послуги були одним рядком.
     * Позиції без числа (priceText «За домовленістю») калькулятор рахувати
     * не вміє, тому сюди не потрапляють.
     *
     * @return ServicePrice[]
     */
    public function findCalculatorOptions(ServiceCity $city): array
    {
        return $this->createQueryBuilder('p')
            // позиції без послуги (озонування) — завжди; з послугою — лише якщо вона й ціни на неї є в цьому місті
            ->leftJoin('p.item', 'i')
            ->where('p.city = :city')->setParameter('city', $city)
            ->andWhere('i.id IS NULL OR (:city NOT MEMBER OF i.hiddenCities AND :city NOT MEMBER OF i.priceHiddenCities)')
            ->andWhere('p.active = true')
            ->andWhere('p.inCalculator = true')
            ->andWhere('p.price IS NOT NULL')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return ServicePrice[] Весь прайс міста, згрупується в шаблоні по item */
    public function findActiveByCity(ServiceCity $city): array
    {
        // порядок табів = порядок категорій, потім послуг (i.position повторюється між категоріями)
        return $this->createQueryBuilder('p')
            ->addSelect('i')
            ->join('p.item', 'i')
            ->join('i.category', 'c')
            ->where('p.city = :city')->setParameter('city', $city)
            // послуга не надається / ціни не публікуємо в цьому місті — вкладки цін на головній нема
            ->andWhere(':city NOT MEMBER OF i.hiddenCities')
            ->andWhere(':city NOT MEMBER OF i.priceHiddenCities')
            ->andWhere('p.active = true')
            ->andWhere('i.active = true')
            ->andWhere('c.active = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('i.position', 'ASC')
            ->addOrderBy('p.position', 'ASC')
            ->getQuery()->getResult();
    }
}
