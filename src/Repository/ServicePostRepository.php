<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceAuthor;
use App\Entity\ServicePost;
use App\Entity\ServicePostCategory;
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

    /**
     * Предикат «стаття існує мовою» (дзеркало ServicePost::existsIn): uk і ru — є uk-слаг (ru падає на нього, як і раніше),
     * en — є і slugEn, і titleEn. null — без умови за мовою (як до 22.10.2026).
     */
    public static function localeCondition(?string $locale, string $alias = 'p'): ?string
    {
        return match ($locale) {
            null => null,
            'en' => "($alias.slugEn IS NOT NULL AND $alias.slugEn <> '' AND $alias.titleEn IS NOT NULL AND $alias.titleEn <> '')",
            default => "($alias.slugUk IS NOT NULL AND $alias.slugUk <> '')",
        };
    }

    /** @return ServicePost[] */
    public function findPublishedBySite(ServiceSite $site, int $limit = 50, ?string $locale = null): array
    {
        return $this->published($site, $locale)
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * Сторінка списку блогу (/blog/page/N/): $perPage статей зі зсувом; $perPage <= 0 = усі.
     *
     * @return ServicePost[]
     */
    public function findPublishedBySitePage(ServiceSite $site, int $page, int $perPage, ?string $locale = null): array
    {
        $qb = $this->published($site, $locale)
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');
        if ($perPage > 0) {
            $qb->setFirstResult(max(0, $page - 1) * $perPage)->setMaxResults($perPage);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * «Related guides» під статтею: до $limit інших опублікованих статей сайту мовою $locale, спершу з тієї
     * ж рубрики, решта місць добирається найсвіжішими з інших рубрик.
     *
     * @return ServicePost[]
     */
    public function findRelated(ServiceSite $site, ServicePost $post, string $locale, int $limit = 3): array
    {
        $base = fn () => $this->published($site, $locale)
            ->andWhere('p.id != :self')->setParameter('self', $post->getId())
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');

        $same = [];
        if (null !== $post->getCategory()) {
            $same = $base()->andWhere('p.category = :cat')->setParameter('cat', $post->getCategory())
                ->setMaxResults($limit)->getQuery()->getResult();
        }
        if (\count($same) >= $limit) {
            return $same;
        }

        $qb = $base()->setMaxResults($limit - \count($same));
        if ([] !== $same) {
            $qb->andWhere('p.id NOT IN (:taken)')
                ->setParameter('taken', array_map(static fn (ServicePost $p): ?int => $p->getId(), $same));
        }

        return [...$same, ...$qb->getQuery()->getResult()];
    }

    /** Чи є хоч одна опублікована стаття (меню й sitemap ховають порожній блог). */
    public function countPublishedBySite(ServiceSite $site, ?string $locale = null): int
    {
        return (int) $this->published($site, $locale)
            ->select('COUNT(p.id)')
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Сторінка статей рубрики чи автора (контентний сайт): мовою $locale, свіжі першими; $perPage <= 0 = усі.
     *
     * @return ServicePost[]
     */
    public function findPublishedFiltered(ServiceSite $site, string $locale, ?ServicePostCategory $category, ?ServiceAuthor $author, int $page, int $perPage): array
    {
        $qb = $this->filtered($site, $locale, $category, $author)
            ->orderBy('p.publishedAt', 'DESC')
            ->addOrderBy('p.id', 'DESC');
        if ($perPage > 0) {
            $qb->setFirstResult(max(0, $page - 1) * $perPage)->setMaxResults($perPage);
        }

        return $qb->getQuery()->getResult();
    }

    public function countPublishedFiltered(ServiceSite $site, string $locale, ?ServicePostCategory $category, ?ServiceAuthor $author): int
    {
        return (int) $this->filtered($site, $locale, $category, $author)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * «Обрані» статті для головної контентного сайту, свіжі першими.
     *
     * @return ServicePost[]
     */
    public function findFeatured(ServiceSite $site, string $locale, int $limit): array
    {
        return $this->published($site, $locale)
            ->andWhere('p.featured = true')
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    private function filtered(ServiceSite $site, string $locale, ?ServicePostCategory $category, ?ServiceAuthor $author): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->published($site, $locale);
        if ($category !== null) {
            $qb->andWhere('p.category = :cat')->setParameter('cat', $category);
        }
        if ($author !== null) {
            $qb->andWhere('p.author = :author')->setParameter('author', $author);
        }

        return $qb;
    }

    /** Опубліковані статті сайту (+ умова мови, якщо задано) */
    private function published(ServiceSite $site, ?string $locale): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.site = :site')->setParameter('site', $site)
            ->andWhere('p.active = true')
            ->andWhere('p.publishedAt IS NOT NULL AND p.publishedAt <= :now')->setParameter('now', new \DateTimeImmutable());
        $cond = self::localeCondition($locale);
        if ($cond !== null) {
            $qb->andWhere($cond);
        }

        return $qb;
    }

    /**
     * Пошук по слагу колонки поточної локалі.
     * Для ru порожній slugRu = «використовується uk-слаг» (див. ServiceCategoryRepository).
     */
    public function findOnePublishedBySlug(ServiceSite $site, string $slug, string $locale): ?ServicePost
    {
        $condition = match ($locale) {
            'ru' => '(p.slugRu = :slug OR (p.slugRu IS NULL AND p.slugUk = :slug))',
            'en' => "(p.slugEn = :slug AND p.titleEn IS NOT NULL AND p.titleEn <> '')",
            default => 'p.slugUk = :slug',
        };

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
