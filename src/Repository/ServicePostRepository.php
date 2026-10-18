<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServicePost>
 *
 * «Зараз» передаємо з PHP (:now), а не CURRENT_TIMESTAMP(): Doctrine пише дати в таймзоні PHP
 * (Europe/Kyiv), а MySQL рахує CURRENT_TIMESTAMP у своїй (UTC у контейнері) — свіжий пост
 * «з майбутнього» три години віддавав 404 (спіймано приймачем content-studio 2026-09-09).
 */
class ServicePostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServicePost::class);
    }

    /** @return ServicePost[] */
    public function findPublishedBySite(ServiceSite $site, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.site = :site')->setParameter('site', $site)
            ->andWhere('p.active = true')
            ->andWhere('p.publishedAt IS NOT NULL AND p.publishedAt <= :now')->setParameter('now', new \DateTimeImmutable())
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Сторінка списку блогу (/blog/page/N/): $perPage статей зі зсувом; $perPage <= 0 = усі.
     *
     * @return ServicePost[]
     */
    public function findPublishedBySitePage(ServiceSite $site, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.site = :site')->setParameter('site', $site)
            ->andWhere('p.active = true')
            ->andWhere('p.publishedAt IS NOT NULL AND p.publishedAt <= :now')->setParameter('now', new \DateTimeImmutable())
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');
        if ($perPage > 0) {
            $qb->setFirstResult(max(0, $page - 1) * $perPage)->setMaxResults($perPage);
        }

        return $qb->getQuery()->getResult();
    }

    /** Чи є хоч одна опублікована стаття (меню й sitemap ховають порожній блог). */
    public function countPublishedBySite(ServiceSite $site): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.site = :site')->setParameter('site', $site)
            ->andWhere('p.active = true')
            ->andWhere('p.publishedAt IS NOT NULL AND p.publishedAt <= :now')->setParameter('now', new \DateTimeImmutable())
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Пошук по слагу колонки поточної локалі.
     * Для ru порожній slugRu = «використовується uk-слаг» (див. ServiceCategoryRepository).
     */
    public function findOnePublishedBySlug(ServiceSite $site, string $slug, string $locale): ?ServicePost
    {
        $condition = $locale === 'ru'
            ? '(p.slugRu = :slug OR (p.slugRu IS NULL AND p.slugUk = :slug))'
            : 'p.slugUk = :slug';

        return $this->createQueryBuilder('p')
            ->where('p.site = :site')->setParameter('site', $site)
            ->andWhere($condition)->setParameter('slug', $slug)
            ->andWhere('p.active = true')
            ->andWhere('p.publishedAt IS NOT NULL AND p.publishedAt <= :now')->setParameter('now', new \DateTimeImmutable())
            ->orderBy('p.slugRu', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function findOneBySiteAndLegacyId(ServiceSite $site, int $legacyId): ?ServicePost
    {
        return $this->findOneBy(['site' => $site, 'legacyId' => $legacyId]);
    }
}
