<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServiceDistrict;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceDistrict>
 */
class ServiceDistrictRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceDistrict::class);
    }

    /** @return ServiceDistrict[] */
    public function findActiveByCity(ServiceCity $city): array
    {
        return $this->findBy(['city' => $city, 'active' => true], ['position' => 'ASC', 'nameUk' => 'ASC']);
    }

    /**
     * Райони, згруповані по адмінрайонах для акордеона «Де ми працюємо».
     * Порядок груп — за першою появою (position районів); без групи — в кінці.
     *
     * @return list<array{districts: ServiceDistrict[]}>
     */
    public function findActiveByCityGrouped(ServiceCity $city): array
    {
        $grouped = [];
        $ungrouped = [];
        foreach ($this->findActiveByCity($city) as $district) {
            $key = $district->getGroupUk();
            if ($key === null) {
                $ungrouped[] = $district;
            } else {
                $grouped[$key]['districts'][] = $district;
            }
        }
        if ($ungrouped !== []) {
            $grouped['']['districts'] = $ungrouped;
        }

        return array_values($grouped);
    }

    /**
     * Офіційні райони міста - верхній рівень лінк-мережі.
     *
     * @return ServiceDistrict[]
     */
    public function findPrimaryByCity(ServiceCity $city): array
    {
        return $this->findBy(
            ['city' => $city, 'active' => true, 'isPrimary' => true],
            ['position' => 'ASC', 'nameUk' => 'ASC'],
        );
    }

    /**
     * Місцевості всередині адмінрайону (без самої сторінки району).
     *
     * @return ServiceDistrict[]
     */
    public function findInGroup(ServiceDistrict $district, int $limit = 12): array
    {
        $group = $district->getGroupUk();
        if ($group === null) {
            return [];
        }

        $all = $this->createQueryBuilder('d')
            ->andWhere('d.city = :city AND d.active = 1 AND d.groupUk = :group AND d.id != :self')
            ->andWhere('d.isPrimary = 0')
            ->setParameter('city', $district->getCity())
            ->setParameter('group', $group)
            ->setParameter('self', $district->getId())
            ->orderBy('d.position', 'ASC')
            ->addOrderBy('d.nameUk', 'ASC')
            ->getQuery()
            ->getResult();

        // вікно зсуваємо від самої локації, інакше в групі з 39 передмість
        // усі посилання діставались би одним і тим самим шести
        if (\count($all) <= $limit) {
            return $all;
        }

        $start = $district->getPosition() % \count($all);
        $window = \array_slice($all, $start, $limit);

        return \count($window) < $limit
            ? array_merge($window, \array_slice($all, 0, $limit - \count($window)))
            : $window;
    }

    /**
     * Ротований зріз локацій для блоку на сторінці послуги: різні послуги
     * показують різні райони, тож вхідні лінки розходяться по всій базі.
     *
     * @return ServiceDistrict[]
     */
    public function findRotated(ServiceCity $city, int $offset, int $limit): array
    {
        $all = $this->findBy(
            ['city' => $city, 'active' => true, 'isPrimary' => false],
            ['position' => 'ASC', 'nameUk' => 'ASC'],
        );
        if ($all === []) {
            return [];
        }

        $start = $offset % \count($all);
        $slice = \array_slice($all, $start, $limit);
        if (\count($slice) < $limit) {
            $slice = array_merge($slice, \array_slice($all, 0, $limit - \count($slice)));
        }

        return $slice;
    }

    /**
     * Пошук по слагу колонки поточної локалі.
     * Для ru порожній slugRu = «використовується uk-слаг» (див. ServiceCategoryRepository).
     */
    public function findOneActiveBySlug(ServiceCity $city, string $slug, string $locale): ?ServiceDistrict
    {
        if ($locale === 'ru') {
            return $this->findOneBy(['city' => $city, 'slugRu' => $slug, 'active' => true])
                ?? $this->findOneBy(['city' => $city, 'slugRu' => null, 'slugUk' => $slug, 'active' => true]);
        }

        return $this->findOneBy(['city' => $city, 'slugUk' => $slug, 'active' => true]);
    }

    public function findOneByCityAndLegacyId(ServiceCity $city, int $legacyId): ?ServiceDistrict
    {
        return $this->findOneBy(['city' => $city, 'legacyId' => $legacyId]);
    }
}
