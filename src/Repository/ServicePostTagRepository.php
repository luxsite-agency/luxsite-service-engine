<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServicePostTag;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ServicePostTag> */
class ServicePostTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServicePostTag::class);
    }

    /**
     * Теги сайту (будь-якої активності), у яких хоч одна мова має слаг зі списку: кандидати для приймача студії, щоб не
     * вантажити всі теги бренду на кожну публікацію.
     *
     * @param list<string> $slugs
     *
     * @return list<ServicePostTag>
     */
    public function findBySiteAndSlugs(ServiceSite $site, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->where('t.site = :site')->setParameter('site', $site)
            ->andWhere('t.slugUk IN (:slugs) OR t.slugRu IN (:slugs) OR t.slugEn IN (:slugs)')->setParameter('slugs', $slugs)
            ->orderBy('t.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** Активний тег за слагом мови (ru — з фолбеком на uk-слаг, як у рубрик) */
    public function findOneActiveBySlug(ServiceSite $site, string $slug, string $locale): ?ServicePostTag
    {
        $field = match ($locale) {
            'ru' => 'slugRu',
            'en' => 'slugEn',
            default => 'slugUk',
        };
        $candidates = $this->findBy(['site' => $site, 'active' => true, $field => $slug]);
        if ($candidates === [] && $locale === 'ru') {
            $candidates = $this->findBy(['site' => $site, 'active' => true, 'slugRu' => null, 'slugUk' => $slug]);
        }
        foreach ($candidates as $t) {
            if ($t->existsIn($locale) && $t->slug($locale) === $slug) {
                return $t;
            }
        }

        return null;
    }
}
