<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCalcTypeCity;
use App\Entity\ServiceCity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceCalcTypeCity>
 */
class ServiceCalcTypeCityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCalcTypeCity::class);
    }

    /**
     * Перевизначення міста, розкладені по id типу - щоб накласти їх на список
     * типів одним проходом, без запиту на кожен рядок.
     *
     * @return array<int, ServiceCalcTypeCity>
     */
    public function mapByTypeForCity(ServiceCity $city): array
    {
        $map = [];
        foreach ($this->findBy(['city' => $city]) as $row) {
            $typeId = $row->getType()?->getId();
            if ($typeId !== null) {
                $map[$typeId] = $row;
            }
        }

        return $map;
    }
}
