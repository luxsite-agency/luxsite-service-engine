<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceDistrict;
use App\Entity\ServiceItem;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use App\ServiceSite\CatalogUrls;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Авто-301 (модель shop-engine E3): зміна slug (uk або ru) послуги, категорії, поста чи району через ORM (адмінка,
 * імпорт, API) → правило auto зі старої адреси на нову по кожній мові. Адреси будуються тими ж маршрутами, що й сайт
 * (sv_service, sv_category, sv_blog_post, sv_district — з урахуванням app.catalog.url_scheme і app.blog_prefix).
 * Послуги, категорії й пости живуть на всіх хостах сайту — правило на сайт (city null); район — лише на хості свого
 * міста — правило міста. Зміна slug категорії в схемах nested/root переносить і всі її послуги. ru-адреса без власного
 * slug бере uk-slug, тому зміна slug uk переносить і ru-адресу. Нова сутність або slug, що повернувся, знімає правило з
 * тієї ж адреси: сторінка знову жива. Видалення чи вимкнення сутності правил не створює (410 — лише вручну).
 * Старі значення — зі змін onFlush, нові — після flush; запис — поза транзакцією flush, окремими запитами DBAL.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AutoRedirects
{
    /** @var array<int, array{0: object, 1: array{uk: string, ru: string}|null, 2: list<array{0: ServiceItem, 1: array{uk: string, ru: string}}>}> null — вставка */
    private array $changed = [];

    public function __construct(
        private readonly RedirectManager $redirects,
        private readonly UrlGeneratorInterface $urls,
        private readonly CatalogUrls $catalogUrls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (self::supports($entity)) {
                $this->changed[spl_object_id($entity)] ??= [$entity, null, []];
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!self::supports($entity)) {
                continue;
            }
            $set = $uow->getEntityChangeSet($entity);
            if (!\array_key_exists('slugUk', $set) && !\array_key_exists('slugRu', $set)) {
                continue;
            }
            $oldUk = (string) ($set['slugUk'][0] ?? $entity->getSlugUk());
            $oldRu = \array_key_exists('slugRu', $set) ? $set['slugRu'][0] : $entity->getSlugRu();
            $old = ['uk' => $oldUk, 'ru' => \is_string($oldRu) && $oldRu !== '' ? $oldRu : $oldUk];
            $items = [];
            if ($entity instanceof ServiceCategory && $this->catalogUrls->isNested()) {
                // послуги категорії переїжджають разом з нею; їхні slug беремо як є (якщо змінюються в тому ж flush — своїм записом)
                foreach ($em->getRepository(ServiceItem::class)->findBy(['category' => $entity]) as $item) {
                    $items[] = [$item, ['uk' => $item->slug('uk'), 'ru' => $item->slug('ru')]];
                }
            }
            $this->changed[spl_object_id($entity)] ??= [$entity, $old, $items];
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->changed === []) {
            return;
        }
        $changed = $this->changed;
        $this->changed = [];
        /** @var array<string, array{0: ServiceSite, 1: ServiceCity|null, 2: list<array{0: string, 1: string}>, 3: list<string>}> $batches */
        $batches = [];
        foreach ($changed as [$entity, $old, $items]) {
            $site = self::siteOf($entity);
            $city = $entity instanceof ServiceDistrict ? $entity->getCity() : null;
            if ($site === null || ($entity instanceof ServiceDistrict && $city === null)) {
                continue;
            }
            $key = $site->getId() . ':' . ($city?->getId() ?? 0);
            $batches[$key] ??= [$site, $city, [], []];
            foreach (['uk', 'ru'] as $locale) {
                $new = $this->path($entity, $locale, null);
                if ($new === null) {
                    continue;
                }
                if ($old === null) {
                    $batches[$key][3][] = $new;
                } elseif (($oldPath = $this->path($entity, $locale, $old[$locale])) !== null && $oldPath !== $new) {
                    $batches[$key][2][] = [$oldPath, $new];
                }
            }
            if ($old !== null && $entity instanceof ServiceCategory) {
                foreach ($items as [$item, $itemSlugs]) {
                    foreach (['uk', 'ru'] as $locale) {
                        $new = $this->itemPath($item, $locale, $entity->slug($locale), $item->slug($locale));
                        $oldPath = $this->itemPath($item, $locale, $old[$locale], $itemSlugs[$locale]);
                        if ($new !== null && $oldPath !== null && $oldPath !== $new) {
                            $batches[$key][2][] = [$oldPath, $new];
                        }
                    }
                }
            }
        }
        foreach ($batches as [$site, $city, $moves, $appeared]) {
            try {
                if ($appeared !== []) {
                    $this->redirects->release($site, $city, array_values(array_filter(array_map(RedirectPath::normalize(...), $appeared))));
                }
                if ($moves !== []) {
                    $this->redirects->recordMoves($site, $city, $moves);
                }
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->logger->warning('Авто-301 не записано: {message}', ['message' => $e->getMessage()]);
            }
        }
    }

    /** Шлях сторінки сутності мовою $locale зі slug $slug (null — поточний). */
    private function path(object $entity, string $locale, ?string $slug): ?string
    {
        $slug ??= $entity->slug($locale);

        return match (true) {
            $entity instanceof ServiceItem => $this->itemPath($entity, $locale, $entity->getCategory()?->slug($locale) ?? '', $slug),
            $entity instanceof ServiceCategory => $this->catalogUrls->isNested() ? $this->generate('sv_category', ['category' => $slug, '_locale' => $locale]) : null,
            $entity instanceof ServiceDistrict => $this->generate('sv_district', ['slug' => $slug, '_locale' => $locale]),
            $entity instanceof ServicePost => $this->generate('sv_blog_post', ['slug' => $slug, '_locale' => $locale]),
            default => null,
        };
    }

    private function itemPath(ServiceItem $item, string $locale, string $categorySlug, string $slug): ?string
    {
        $params = ['slug' => $slug, '_locale' => $locale];
        if ($this->catalogUrls->isNested()) {
            if ($categorySlug === '') {
                return null;
            }
            $params['category'] = $categorySlug;
        }

        return $this->generate('sv_service', $params);
    }

    /** @param array<string, string> $params */
    private function generate(string $route, array $params): ?string
    {
        if (($params['slug'] ?? '-') === '' || ($params['category'] ?? '-') === '') {
            return null;
        }
        try {
            $url = $this->urls->generate($route, $params);
        } catch (RoutingException) {
            return null;
        }
        $base = $this->urls->getContext()->getBaseUrl();

        return $base !== '' && str_starts_with($url, $base) ? substr($url, \strlen($base)) : $url;
    }

    private static function siteOf(object $entity): ?ServiceSite
    {
        return match (true) {
            $entity instanceof ServiceDistrict => $entity->getCity()?->getSite(),
            $entity instanceof ServiceItem, $entity instanceof ServiceCategory, $entity instanceof ServicePost => $entity->getSite(),
            default => null,
        };
    }

    /** @phpstan-assert-if-true ServiceItem|ServiceCategory|ServiceDistrict|ServicePost $entity */
    private static function supports(object $entity): bool
    {
        return $entity instanceof ServiceItem || $entity instanceof ServiceCategory || $entity instanceof ServiceDistrict || $entity instanceof ServicePost;
    }
}
