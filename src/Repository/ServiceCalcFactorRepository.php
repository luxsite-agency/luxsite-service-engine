<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCalcFactor;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceCalcFactor>
 */
class ServiceCalcFactorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceCalcFactor::class);
    }

    /**
     * Активні коефіцієнти сайту, згруповані по групах у порядку self::GROUPS.
     * Групи без варіантів пропускаються (їх блоки не рендеряться).
     *
     * @return array<string, ServiceCalcFactor[]>
     */
    public function findActiveBySiteGrouped(ServiceSite $site): array
    {
        $rows = $this->findBy(['site' => $site, 'active' => true], ['position' => 'ASC', 'id' => 'ASC']);

        $grouped = [];
        foreach (ServiceCalcFactor::GROUPS as $group) {
            foreach ($rows as $row) {
                if ($row->getGroupCode() === $group) {
                    $grouped[$group][] = $row;
                }
            }
        }

        return $grouped;
    }
}
