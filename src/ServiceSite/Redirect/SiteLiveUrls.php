<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use App\EventSubscriber\SiteModeSubscriber;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceDistrictRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePostCategoryRepository;
use App\Repository\ServicePostRepository;
use App\Repository\ServicePostTagRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Жива адреса сайту = GET на хості міста не дав би 404: маршрут сайту знайдено і, для сторінок сутностей, сутність є й
 * видима в місті (послуга активна й надається в місті чи хоч в одному іншому — тоді контролер дає 301, не 404;
 * категорія активна з послугами в місті; район активний у місті; пост опублікований; рубрика й тег статей активні). Маршрути адмінки на хості сайту —
 * 404 (AdminHostSubscriber), тож не живі. Невідомий /<x>/ (sv_legacy_redirect, категорія root-схеми) не живий, навіть
 * якщо для нього є правило паку (app.legacy_redirects, prefix_redirects): пак спрацьовує до роутингу, тож правило з
 * БД для такої адреси нешкідливе — стоїть у резерві, доки адреса є в паку; інакше «Перенести з паку» і правила на
 * адреси з мапи були б неможливі. Лише для збереження ручних правил — не на запитах сайту.
 *
 * Матчер бере хост і метод з RequestContext роутера, а умови маршрутів (request.attributes.has('service_city')) — з
 * запиту: тому на час перевірки — власний запит GET https://<хост міста>/<шлях> з атрибутами тенанта й власний контекст,
 * потім попередній (параметри контексту, зокрема _functions для умов зі service(), — з попереднього).
 */
final class SiteLiveUrls implements LiveUrls
{
    /** Маршрути рубрики блогу (сторінка, пагінація, RSS; під розділом і від кореня) */
    private const POST_CATEGORY_ROUTES = ['sv_blog_category', 'sv_blog_category_page', 'sv_blog_category_root', 'sv_blog_category_root_page', 'sv_blog_category_feed', 'sv_blog_category_root_feed'];

    public function __construct(
        #[Autowire(service: 'router')] private readonly RouterInterface $router,
        private readonly ServiceCityRepository $cities,
        private readonly ServiceItemRepository $items,
        private readonly ServiceCategoryRepository $categories,
        private readonly ServiceDistrictRepository $districts,
        private readonly ServicePostRepository $posts,
        private readonly ServicePostTagRepository $tags,
        private readonly ServicePostCategoryRepository $postCategories,
    ) {
    }

    public function isLive(ServiceSite $site, ?ServiceCity $city, string $path): bool
    {
        $cities = $city !== null ? [$city] : $this->cities->findAllActiveBySite($site);
        foreach ($cities as $one) {
            if ($this->isLiveOn($site, $one, $path)) {
                return true;
            }
        }

        return false;
    }

    private function isLiveOn(ServiceSite $site, ServiceCity $city, string $path): bool
    {
        $request = Request::create('https://' . $city->getHost() . RedirectPath::encode($path));
        $request->attributes->set(ServiceHostSubscriber::ATTR_SITE, $site);
        $request->attributes->set(ServiceHostSubscriber::ATTR_CITY, $city);
        $previous = $this->router->getContext();
        // параметри контексту роутера — з собою: _functions (router.expression_language_provider) потрібен умовам
        // маршрутів зі service(), напр. service('root_slugs') схеми slug і сторінок app.pages; без нього матчер — 500
        $this->router->setContext((new RequestContext())->fromRequest($request)->setParameters($previous->getParameters()));
        try {
            $match = $this->router->matchRequest($request);
        } catch (RoutingException) {
            return false;
        } finally {
            $this->router->setContext($previous);
        }
        $route = (string) ($match['_canonical_route'] ?? $match['_route'] ?? '');
        $locale = ($match['_locale'] ?? 'uk') === 'ru' ? 'ru' : 'uk';
        // статті, рубрики й теги контентного сайту бувають англійською (каталог і райони — ні): для них — справжня мова адреси
        $contentLocale = (string) ($match['_locale'] ?? $locale);

        return match (true) {
            $route === '' || $route === 'admin' || str_starts_with($route, 'admin_') || str_starts_with($route, 'api_') || str_starts_with($route, 'app_') => false,
            // маршрут, вимкнений режимом сайту (SiteModeSubscriber → 404): /feed/, /author/ на сервісному, /services/ на контентному
            !SiteModeSubscriber::allowed($site, $route) => false,
            $route === 'sv_service' => $this->itemLive($site, $city, (string) $match['slug'], $locale),
            $route === 'sv_category' => $this->categoryLive($site, $city, (string) $match['category'], $locale),
            $route === 'sv_district' => $this->districts->findOneActiveBySlug($city, (string) $match['slug'], $locale) !== null,
            $route === 'sv_blog_post' => $this->posts->findOnePublishedBySlug($site, (string) $match['slug'], $contentLocale) !== null,
            // рубрика (25.10.2026): /<блог>/category/<slug>/ приймає будь-який slug — жива лише активна рубрика; адреса вимкненої
            // віддає 404, тож на неї можна завести 301 (рубрики не видаляємо)
            \in_array($route, self::POST_CATEGORY_ROUTES, true) => $this->postCategories->findOneActiveBySlug($site, (string) $match['slug'], $contentLocale) !== null,
            // тег (24.10.2026): адреса злитого чи перейменованого тегу не жива — на неї можна завести 301
            $route === 'sv_blog_tag' || $route === 'sv_blog_tag_page' => $this->tags->findOneActiveBySlug($site, (string) $match['slug'], $contentLocale) !== null,
            $route === 'sv_legacy_redirect' => false,
            default => true,
        };
    }

    private function itemLive(ServiceSite $site, ServiceCity $city, string $slug, string $locale): bool
    {
        $item = $this->items->findOneActiveBySiteAndSlug($site, $slug, $locale);
        if ($item === null) {
            return false;
        }
        if ($item->isAvailableIn($city)) {
            return true;
        }
        // не надається тут → контролер дає 301 у місто, де є (не 404); нема ніде → 404
        foreach ($this->cities->findAllActiveBySite($site) as $other) {
            if ($item->isAvailableIn($other)) {
                return true;
            }
        }

        return false;
    }

    private function categoryLive(ServiceSite $site, ServiceCity $city, string $slug, string $locale): bool
    {
        $category = $this->categories->findOneActiveBySlug($site, $slug, $locale);
        if ($category === null) {
            return false; // root-схема: невідомий /<x>/ — 404 або правило паку (не жива)
        }

        return $this->items->findActiveByCategory($category, $city) !== [];
    }
}
