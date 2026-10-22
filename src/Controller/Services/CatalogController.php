<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Repository\ServiceCalcFactorRepository;
use App\Repository\ServiceCalcTypeRepository;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceDistrictRepository;
use App\Repository\ServiceCategoryCityRepository;
use App\Repository\ServiceItemCityRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePriceRepository;
use App\Repository\ServiceReviewRepository;
use App\Repository\ServiceWorkRepository;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\CrossCityDistricts;
use App\ServiceSite\LegacyUrls;
use App\ServiceSite\ServiceCityAware;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Каталог послуг. Схема URL — з паку (app.catalog.url_scheme, config/routes/catalog/*.yaml):
 *  - flat: /services/ → /services/<послуга>/, сторінок категорій нема
 *    (прибрані, щоб не конкурувати з картками послуг у видачі);
 *  - nested: /services/ → /services/<категорія>/ → /services/<категорія>/<послуга>/;
 *  - root: /<категорія>/ → /<категорія>/<послуга>/ від кореня сайту (сайт з Joomla).
 * Маршрути sv_category і sv_service тому без атрибутів — їх задає YAML схеми.
 * Слаги per-мова: пошук по колонці поточної локалі.
 */
final class CatalogController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly ServiceCategoryRepository $categories,
        private readonly ServiceItemRepository $items,
        private readonly ServicePriceRepository $prices,
        private readonly ServiceCalcTypeRepository $calcTypes,
        private readonly ServiceCalcFactorRepository $calcFactors,
        private readonly ServiceDistrictRepository $districts,
        private readonly ServiceItemCityRepository $itemTexts,
        private readonly ServiceCategoryCityRepository $categoryTexts,
        private readonly ServiceWorkRepository $works,
        private readonly ServiceReviewRepository $reviews,
        private readonly \App\ServiceSite\ReviewsRating $reviewsRating,
        private readonly CatalogUrls $catalogUrls,
        private readonly ServiceCityRepository $cityRepo,
        private readonly CrossCityDistricts $crossCity,
        private readonly LegacyUrls $legacy,
    ) {
    }

    #[Route(
        path: ['uk' => '/services/', 'ru' => '/ru/services/'],
        name: 'sv_services',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function index(Request $request): Response
    {
        $site = $this->serviceSite($request);

        $itemsByCategory = $this->itemsByCategory($request);

        return $this->render('services/catalog/index.html.twig', [
            'site' => $site,
            'city' => $this->serviceCity($request),
            // категорії без жодної послуги в цьому місті не показуємо
            'categories' => array_values(array_filter($this->categories->findActiveBySite($site), static fn ($c) => isset($itemsByCategory[$c->getId()]))),
            'itemsByCategory' => $itemsByCategory,
            'altUrls' => $this->altUrls('sv_services'),
        ]);
    }

    /**
     * Сторінка категорії — у схемах nested і root (маршрут sv_category з config/routes/catalog/*.yaml).
     * У root маршрут /<x>/ перекриває sv_legacy_redirect (той має нижчий пріоритет), тому невідома категорія
     * спершу шукається серед старих адрес паку (app.legacy_redirects) і лише потім — 404.
     */
    public function category(Request $request, string $category): Response
    {
        $site = $this->serviceSite($request);
        $cat = $this->categories->findOneActiveBySlug($site, $category, $request->getLocale());
        if ($cat === null) {
            if ($this->catalogUrls->isRoot() && ($target = $this->legacy->legacyTarget($request, '/' . $category . '/')) !== null) {
                if (!str_contains($target, '?')) {
                    $target .= $this->legacy->queryFor($request, (string) parse_url($target, PHP_URL_PATH));
                }
                $response = $this->redirect($target, 301);
                // мапа паку змінна: 301 без заголовків кешу браузер тримає назавжди (див. LegacyRedirectController)
                $response->headers->set('Cache-Control', 'no-store, max-age=0');

                return $response;
            }

            throw $this->createNotFoundException('Категорію не знайдено.');
        }

        $city = $this->serviceCity($request);
        $items = $this->items->findActiveByCategory($cat, $city);
        if ($items === []) {
            throw $this->createNotFoundException('У цьому місті послуг категорії нема.');
        }

        return $this->render('services/catalog/category.html.twig', [
            'site' => $site,
            'city' => $city,
            'category' => $cat,
            // тексти категорії під місто (порожні поля падають на категорію)
            'override' => $this->categoryTexts->findOneByCategoryAndCity($cat, $city),
            'items' => $items,
            'altUrls' => $this->altUrls('sv_category', static fn (string $l): array => ['category' => $cat->slug($l)]),
        ]);
    }

    /**
     * $category приходить у схемах nested і root. Послуга під чужою категорією (/<інша>/<послуга>/ — Joomla віддавала
     * таку сторінку з 200, і подібні адреси є в індексі) — 301 на її справжню адресу, а не дубль і не 404.
     */
    public function item(Request $request, string $slug, ?string $category = null): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);
        $locale = $request->getLocale();

        $item = $this->items->findOneActiveBySiteAndSlug($site, $slug, $locale)
            ?? throw $this->createNotFoundException('Послугу не знайдено.');
        $cat = $item->getCategory();
        $foreignCategory = $category !== null && $cat->slug($locale) !== $category;
        if ($foreignCategory && !$cat->isActive()) {
            throw $this->createNotFoundException('Послугу не знайдено в цій категорії.');
        }
        // query (utm, gclid…) їде разом з 301
        $query = $request->getQueryString();
        $query = $query !== null && $query !== '' ? '?' . $query : '';
        $canonicalPath = $this->generateUrl('sv_service', $this->catalogUrls->itemParams($item, $locale) + ['_locale' => $locale]);
        if (!$item->isAvailableIn($city)) {
            // послуга не надається в цьому місті → 301 на її справжню адресу в місті, де вона є (апекс першим), інакше 404.
            // Перевірка до редиректу «чужої категорії», щоб /<інша>/<послуга>/ у такому місті був одним 301, а не двома.
            $target = $this->cityWithItem($request, $item, $canonicalPath);
            if ($target === null) {
                throw $this->createNotFoundException('Послуга в цьому місті не надається.');
            }

            return $this->redirect($target . $query, 301);
        }
        if ($foreignCategory) {
            return $this->redirect($canonicalPath . $query, 301);
        }

        // міні-калькулятор показуємо лише там, де послуга рахується за площею
        // (у неї в адмінці обрано тип прибирання)
        $calcTypes = $item->getCalcType() !== null ? $this->calcTypes->findActiveForCity($site, $city) : [];

        return $this->render('services/catalog/item.html.twig', [
            'site' => $site,
            'city' => $city,
            'category' => $cat,
            'item' => $item,
            // текст під місто: порожньо - лишається загальний текст послуги
            'override' => $this->itemTexts->findOneByItemAndCity($item, $city),
            // ціни не публікуємо в цьому місті — без таблиці й Offer у розмітці (прайс лишається в адмінці)
            'prices' => $item->showsPricesIn($city) ? $this->prices->findActiveByItemAndCity($item, $city) : [],
            // ротований блок локацій: різні послуги ведуть на різні райони,
            // тож вхідні лінки розходяться по всій базі районних сторінок
            'districtLinks' => array_merge(
                $this->districts->findPrimaryByCity($city),
                $this->districts->findRotated($city, (int) $item->getId() * 7, 12),
            ),
            // власні фото: спершу прив'язані до цієї послуги, далі загальні
            'works' => $this->works->findForItem($item, $city),
            // рейтинг для Product у розмітці: рахується з наших же
            // відгуків, тому число має збігатися з тим, що на /reviews/
            // загальна оцінка (рядок + aggregateRating): з віджета gbp-manager, якщо підключений, інакше власна (ReviewsRating)
            'reviewsAggregate' => $this->reviewsRating->aggregate($city),
            'calcTypes' => $calcTypes,
            'calcFactors' => $calcTypes !== [] ? $this->calcFactors->findActiveBySiteGrouped($site) : [],
            'related' => array_values(array_filter(
                $this->items->findActiveByCategory($cat, $city),
                static fn ($i) => $i->getId() !== $item->getId(),
            )),
            'altUrls' => $this->altUrls('sv_service', fn (string $l): array => $this->catalogUrls->itemParams($item, $l)),
        ]);
    }

    /** @return array<int, \App\Entity\ServiceItem[]> Ключ — id категорії */
    private function itemsByCategory(Request $request): array
    {
        $grouped = [];
        foreach ($this->items->findActiveBySite($this->serviceSite($request), $this->serviceCity($request)) as $item) {
            $grouped[$item->getCategory()->getId()][] = $item;
        }

        return $grouped;
    }

    /** Адреса сторінки послуги ($path — її справжній шлях) у місті сайту, де послуга надається (апекс першим), без query, або null */
    private function cityWithItem(Request $request, \App\Entity\ServiceItem $item, string $path): ?string
    {
        $site = $this->serviceSite($request);
        $cities = $this->cityRepo->findAllActiveBySite($site);
        usort($cities, static fn ($a, $b) => (int) $b->isApex() <=> (int) $a->isApex());
        foreach ($cities as $other) {
            if ($other->isNoindex() || !$item->isAvailableIn($other)) {
                continue;
            }

            return $request->getScheme() . '://' . $this->crossCity->hostFor($other, $site, $request) . $path;
        }

        return null;
    }
}
