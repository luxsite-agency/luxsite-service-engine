<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCalcType;
use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceCalcType>
 */
class ServiceCalcTypeRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ServiceCalcTypeCityRepository $cityPrices,
    ) {
        parent::__construct($registry, ServiceCalcType::class);
    }

    /**
     * Типи з цінами того міста, у якому зараз відвідувач. Прайс партнера
     * різниться по містах, тож усе, що йде на сторінку і в розрахунок заявки,
     * має проходити через цей метод, а не через findActiveBySite.
     *
     * @return ServiceCalcType[]
     */
    public function findActiveForCity(ServiceSite $site, ServiceCity $city): array
    {
        $types = $this->findActiveBySite($site);
        $overrides = $this->cityPrices->mapByTypeForCity($city);

        foreach ($types as $type) {
            $override = $overrides[$type->getId()] ?? null;
            $type->applyCityPrices($override?->getPricePerSqm(), $override?->getMinPrice());
        }

        return $types;
    }

    /**
     * Один тип із цінами міста. Шукаємо серед уже підготованого списку, щоб
     * ціна в розрахунку заявки гарантовано збігалася з показаною на сторінці.
     */
    public function findOneActiveForCity(ServiceSite $site, ServiceCity $city, int $id): ?ServiceCalcType
    {
        foreach ($this->findActiveForCity($site, $city) as $type) {
            if ($type->getId() === $id) {
                return $type;
            }
        }

        return null;
    }

    /** @return ServiceCalcType[] */
    public function findActiveBySite(ServiceSite $site): array
    {
        return $this->findBy(['site' => $site, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneActiveBySite(ServiceSite $site, int $id): ?ServiceCalcType
    {
        return $this->findOneBy(['id' => $id, 'site' => $site, 'active' => true]);
    }
}
