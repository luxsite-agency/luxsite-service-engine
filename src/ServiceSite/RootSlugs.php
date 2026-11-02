<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCategory;
use App\Entity\ServiceItem;
use App\Entity\ServicePage;
use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Routing\Attribute\AsRoutingConditionService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Хто живе в корені сайту (/<слаг>/, /ru/<слаг>/) і чи не збігаються слаги.
 *
 * Корінь ділять: фіксовані розділи (CatalogUrls::reservedSegments — /about/, /services/, префікс блогу…), сторінки
 * app.pages (ServicePage; дочірні — /<батько>/<слаг>/), категорії (схеми root і slug) і послуги (схема slug).
 * Маршрути з однаковим шаблоном (/{slug}/ послуги й /{category}/ категорії в slug.yaml, /{path}/ сторінки) розводяться
 * умовою маршруту: service('root_slugs').matches(kind, request, params) — чи є на сайті активний запис цього виду з
 * таким слагом цієї мови. Не збіглося — роутер іде до наступного маршруту (зрештою sv_legacy_redirect → 301 або 404).
 * Порядок за збігу: фіксована сторінка (priority 10) → сторінка (5) → послуга → категорія; збіг сам по собі — помилка
 * даних: адмінка не дає зберегти такий слаг (conflictsFor), bin/site doctor показує наявні (app:site:slugs, conflicts).
 */
#[AsRoutingConditionService(alias: 'root_slugs')]
final class RootSlugs implements ResetInterface
{
    /** @var array<string, object|null> memo на запит: умова маршруту й контролер шукають той самий запис */
    private array $memo = [];

    public function __construct(
        private readonly CatalogUrls $catalogUrls,
        private readonly ServiceItemRepository $items,
        private readonly ServiceCategoryRepository $categories,
        private readonly ServicePageRepository $pages,
        private readonly EntityManagerInterface $em,
        #[Autowire('%app.pages%')]
        private readonly bool $pagesEnabled = false,
    ) {
    }

    public function pagesEnabled(): bool
    {
        return $this->pagesEnabled;
    }

    /**
     * Умова маршруту: $kind — item | category | page; $params — параметри збігу (_locale, slug / category / path).
     *
     * @param array<string, mixed> $params
     */
    public function matches(string $kind, Request $request, array $params): bool
    {
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        if (!$site instanceof ServiceSite) {
            return false;
        }
        $locale = ($params['_locale'] ?? 'uk') === 'ru' ? 'ru' : 'uk';

        return match ($kind) {
            'item' => $this->item($site, (string) ($params['slug'] ?? ''), $locale) !== null,
            'category' => $this->category($site, (string) ($params['category'] ?? ''), $locale) !== null,
            'page' => $this->page($site, (string) ($params['path'] ?? ''), $locale) !== null,
            default => false,
        };
    }

    /** Активна сторінка app.pages за шляхом («vakansii», «vakansii/likar»); null, коли сторінки вимкнені */
    public function page(ServiceSite $site, string $path, string $locale): ?ServicePage
    {
        $path = trim($path, '/');
        if (!$this->pagesEnabled || $path === '' || $this->isReserved(explode('/', $path)[0])) {
            return null;
        }
        $page = $this->remember('page', $site, $locale, $path, fn () => $this->pages->findOneActiveByPath($site, $path, $locale));

        return $page instanceof ServicePage ? $page : null;
    }

    /** Активна послуга з таким слагом, якщо послуги живуть у корені (схема slug) */
    public function item(ServiceSite $site, string $slug, string $locale): ?ServiceItem
    {
        if (!$this->catalogUrls->itemsAtRoot() || $slug === '' || $this->isReserved($slug)) {
            return null;
        }
        $item = $this->remember('item', $site, $locale, $slug, fn () => $this->items->findOneActiveBySiteAndSlug($site, $slug, $locale));

        return $item instanceof ServiceItem ? $item : null;
    }

    /** Активна категорія з таким слагом, якщо категорії живуть у корені одним сегментом (схема slug) */
    public function category(ServiceSite $site, string $slug, string $locale): ?ServiceCategory
    {
        if (!$this->catalogUrls->isSlug() || $slug === '' || $this->isReserved($slug)) {
            return null;
        }
        $category = $this->remember('category', $site, $locale, $slug, fn () => $this->categories->findOneActiveBySlug($site, $slug, $locale));

        return $category instanceof ServiceCategory ? $category : null;
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * Збіги слагів у корені сайту для bin/site doctor (app:site:slugs): кілька записів на одній адресі або запис на
     * адресі фіксованого розділу. Вимкнені записи теж рахуються — увімкнення не має зробити сторінку недосяжною.
     *
     * @return list<string> по рядку на адресу: «uk /x/: послуга «A» (#5), категорія «B» (#2)»
     */
    public function conflicts(ServiceSite $site): array
    {
        $byAddress = [];
        foreach ($this->entries($site) as $e) {
            $byAddress[$e['locale'] . ' ' . $e['address']][] = $e;
        }
        $out = [];
        foreach ($byAddress as $address => $entries) {
            $reserved = \in_array($entries[0]['address'], $this->reservedSegments(), true);
            if (\count($entries) < 2 && !$reserved) {
                continue;
            }
            [$locale, $path] = explode(' ', $address, 2);
            $out[] = sprintf('%s %s: %s%s', $locale, self::url($locale, $path), implode(', ', array_map(self::label(...), $entries)),
                $reserved ? ' — адреса фіксованого розділу сайту' : '');
        }

        return $out;
    }

    /**
     * Чи не займе запис (послуга, категорія, сторінка — з уже зміненими у формі слагами) адресу, де вже є інший запис
     * або фіксований розділ, у мові $locale. Порожньо = можна; інакше — повідомлення для поля слага в адмінці.
     *
     * @return list<string>
     */
    public function conflictsFor(object $record, string $locale): array
    {
        $candidate = $this->entryOf($record, $locale);
        if ($candidate === null) {
            return [];
        }
        $site = $record->getSite();
        if (!$site instanceof ServiceSite) {
            return [];
        }
        $out = [];
        if (\in_array($candidate['address'], $this->reservedSegments(), true)) {
            $out[] = sprintf('Адреса %s зайнята фіксованим розділом сайту (%s).', self::url($locale, $candidate['address']), implode(', ', $this->reservedSegments()));
        }
        foreach ($this->entries($site, $locale) as $e) {
            if ($e['address'] === $candidate['address'] && !($e['kind'] === $candidate['kind'] && $e['id'] === $candidate['id'])) {
                $out[] = sprintf('Адреса %s уже зайнята: %s.', self::url($locale, $e['address']), self::label($e));
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function reservedSegments(): array
    {
        return $this->catalogUrls->reservedSegments();
    }

    private function isReserved(string $segment): bool
    {
        return \in_array($segment, $this->reservedSegments(), true);
    }

    /**
     * Адреса запису в корені в мові $locale (без слешів: «x», «vakansii/x») або null, якщо запис у цій схемі не в корені.
     *
     * @return array{kind: string, id: ?int, title: string, locale: string, address: string}|null
     */
    private function entryOf(object $record, string $locale): ?array
    {
        $kind = match (true) {
            $record instanceof ServicePage && $this->pagesEnabled => 'page',
            $record instanceof ServiceCategory && $this->catalogUrls->categoriesAtRoot() => 'category',
            $record instanceof ServiceItem && $this->catalogUrls->itemsAtRoot() => 'item',
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        /** @var ServicePage|ServiceCategory|ServiceItem $record */
        $slug = self::effectiveSlug($record->getSlugUk(), $record->getSlugRu(), $locale);
        if ($slug === '') {
            return null;
        }
        $address = $record instanceof ServicePage && $record->getParent() !== null
            ? self::effectiveSlug($record->getParent()->getSlugUk(), $record->getParent()->getSlugRu(), $locale) . '/' . $slug
            : $slug;

        return ['kind' => $kind, 'id' => $record->getId(), 'title' => $record->getTitleUk(), 'locale' => $locale, 'address' => $address];
    }

    /**
     * Усі записи сайту, що живуть у корені за поточної схеми, з адресами в кожній мові.
     *
     * @return list<array{kind: string, id: ?int, title: string, locale: string, address: string}>
     */
    private function entries(ServiceSite $site, ?string $onlyLocale = null): array
    {
        $sources = [];
        if ($this->pagesEnabled) {
            $sources['page'] = ServicePage::class;
        }
        if ($this->catalogUrls->categoriesAtRoot()) {
            $sources['category'] = ServiceCategory::class;
        }
        if ($this->catalogUrls->itemsAtRoot()) {
            $sources['item'] = ServiceItem::class;
        }
        $out = [];
        foreach ($sources as $kind => $class) {
            $qb = $this->em->createQueryBuilder()
                ->select('e.id, e.slugUk, e.slugRu, e.titleUk')
                ->from($class, 'e')
                ->where('e.site = :site')->setParameter('site', $site)
                ->orderBy('e.id', 'ASC');
            if ($kind === 'page') {
                $qb->addSelect('parent.slugUk AS parentUk, parent.slugRu AS parentRu')->leftJoin('e.parent', 'parent');
            }
            foreach ($qb->getQuery()->getArrayResult() as $row) {
                foreach ($onlyLocale !== null ? [$onlyLocale] : ['uk', 'ru'] as $locale) {
                    $slug = self::effectiveSlug((string) $row['slugUk'], $row['slugRu'], $locale);
                    if ($slug === '') {
                        continue;
                    }
                    if (($row['parentUk'] ?? null) !== null) {
                        $slug = self::effectiveSlug((string) $row['parentUk'], $row['parentRu'], $locale) . '/' . $slug;
                    }
                    $out[] = ['kind' => $kind, 'id' => (int) $row['id'], 'title' => (string) $row['titleUk'], 'locale' => $locale, 'address' => $slug];
                }
            }
        }

        return $out;
    }

    /** Слаг адреси в мові: ru порожній = uk (як генерує LocalizedFieldsTrait::loc і шукають репозиторії) */
    private static function effectiveSlug(string $uk, ?string $ru, string $locale): string
    {
        return mb_strtolower(trim($locale === 'ru' && $ru !== null && trim($ru) !== '' ? $ru : $uk, '/ '));
    }

    private static function url(string $locale, string $address): string
    {
        return ($locale === 'ru' ? '/ru/' : '/') . $address . '/';
    }

    /** @param array{kind: string, id: ?int, title: string} $e */
    private static function label(array $e): string
    {
        $kind = ['page' => 'сторінка', 'category' => 'категорія', 'item' => 'послуга'][$e['kind']] ?? $e['kind'];

        return sprintf('%s «%s»%s', $kind, $e['title'], $e['id'] !== null ? ' (#' . $e['id'] . ')' : '');
    }

    private function remember(string $kind, ServiceSite $site, string $locale, string $key, callable $load): ?object
    {
        $k = $kind . '|' . $site->getId() . '|' . $locale . '|' . $key;
        if (!\array_key_exists($k, $this->memo)) {
            $this->memo[$k] = $load();
        }

        return $this->memo[$k];
    }
}
