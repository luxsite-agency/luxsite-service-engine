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
     * Активні теги сайту, що існують мовою (слаг і назва), у порядку створення; $locale = null — усі активні.
     *
     * @return list<ServicePostTag>
     */
    public function findActiveBySite(ServiceSite $site, ?string $locale = null): array
    {
        $all = $this->findBy(['site' => $site, 'active' => true], ['id' => 'ASC']);

        return array_values(array_filter($all, static fn (ServicePostTag $t): bool => $locale === null || $t->existsIn($locale)));
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
