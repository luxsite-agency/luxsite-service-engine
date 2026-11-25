<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Repository\ServiceCategoryCityRepository;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceDistrictRepository;
use App\Repository\ServiceItemCityRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePageRepository;
use App\Repository\ServicePostRepository;
use App\Repository\ServiceReviewRepository;
use App\ServiceSite\CalculatorAvailability;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\ServiceCityAware;
use App\ServiceSite\SiteLocales;
use App\Settings\SiteSettingKeys;
use App\Settings\SiteSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Автогенерована карта сайту тенанта: збирається з БД на кожен запит,
 * тож нові послуги/райони/статті потрапляють у неї одразу після публікації.
 * URL — тільки індексовані сторінки (без /lead, /thank-you, /connect і сторінок з галочкою «Не індексувати»).
 * robots.txt тут же: на сервісному хості віддає посилання на карту,
 * на всіх інших (адмінка) — повну заборону.
 */
final class SitemapController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly ServiceCategoryRepository $categories,
        private readonly ServiceItemRepository $items,
        private readonly ServiceDistrictRepository $districts,
        private readonly ServicePostRepository $posts,
        private readonly ServiceCityRepository $cities,
        private readonly ServiceItemCityRepository $itemTexts,
        private readonly CatalogUrls $catalogUrls,
        private readonly CalculatorAvailability $calculator,
        private readonly ServicePageRepository $pages,
        private readonly \App\ServiceSite\FixedPages $fixedPages,
        private readonly ServiceCategoryCityRepository $categoryTexts,
        private readonly ServiceReviewRepository $reviews,
        private readonly SiteSettings $settings,
    ) {
    }

    #[Route(
        path: '/sitemap.xml',
        name: 'sv_sitemap',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function sitemap(Request $request): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);
        // місто в розробці карти сайту не має взагалі
        if ($city->isNoindex()) {
            throw $this->createNotFoundException('Місто закрите від пошуковиків.');
        }

        $entries = [];
        // запис карти: URL сторінки мовами бренду (мова → URL) + lastmod; параметри, що різняться між мовами, — функцією від мови
        // $content — запис сторінки: мовами, якими його нема (en без перекладу), адреси в карті нема (#71);
        // вимкнена фіксована сторінка (app.fixed_pages: false або адреса) — поза картою (#57)
        $add = function (string $route, array|\Closure $params = [], ?\DateTimeInterface $lastmod = null, ?object $content = null) use (&$entries, $site): void {
            if (str_starts_with($route, 'sv_') && in_array(substr($route, 3), \App\ServiceSite\FixedPages::KEYS, true) && !$this->fixedPages->enabled(substr($route, 3))) {
                return;
            }
            if (isset(SiteSettingKeys::NOINDEX_ROUTES[$route]) && $this->settings->getBool($site, SiteSettingKeys::NOINDEX_ROUTES[$route])) {
                return;
            }
            $urls = $this->altUrls($route, $params, $content);
            if ($urls !== []) {
                $entries[] = ['urls' => $urls, 'lastmod' => $lastmod];
            }
        };

        // lastmod беремо з даних сторінки, а не з дати деплою: інакше
        // «змінилось усе й сьогодні» щоразу, і Google перестає вірити даті
        $textDates = $this->itemTexts->updatedAtByItem($city);
        $newest = static fn (?\DateTimeInterface $a, ?\DateTimeInterface $b): ?\DateTimeInterface
            => $a === null ? $b : ($b === null ? $a : ($a > $b ? $a : $b));
        $changed = static fn (object $record): ?\DateTimeInterface => $record->getUpdatedAt() ?? $record->getCreatedAt();
        $newestOf = static fn (array $records): ?\DateTimeInterface
            => array_reduce($records, static fn ($date, $record) => $newest($date, $changed($record)), null);
        $categoryTextDates = [];
        foreach ($this->categoryTexts->findBy(['city' => $city]) as $text) {
            $categoryTextDates[(int) $text->getCategory()->getId()] = $changed($text);
        }
        $siteDate = $changed($site);
        $cityDate = $changed($city);

        $add('sv_home', [], $cityDate);
        // у схемах nested, root і slug у карті є й сторінки категорій
        // послуги, що надаються в цьому місті (sitemap — на хост міста); категорії без них — поза картою
        // послуги з noindex (сторінки без власного змісту) живі, але в карті їм не місце
        $siteItems = array_values(array_filter($this->items->findActiveBySite($site, $city), static fn ($i): bool => !$i->isNoindex()));
        $catsWithItems = [];
        foreach ($siteItems as $item) {
            $catsWithItems[$item->getCategory()->getId()] = true;
        }
        // список послуг: найновіша зміна послуг, їхніх міських текстів і категорій
        $servicesDate = null;
        foreach ($siteItems as $item) {
            $servicesDate = $newest($servicesDate, $newest($changed($item), $textDates[$item->getId()] ?? null));
            $category = $item->getCategory();
            $servicesDate = $newest($servicesDate, $newest($changed($category), $categoryTextDates[$category->getId()] ?? null));
        }
        $add('sv_services', [], $servicesDate);
        if ($this->catalogUrls->hasCategoryPages()) {
            foreach ($this->categories->findActiveBySite($site) as $category) {
                if (!$category->isLanding() || $category->isNoindex() || !isset($catsWithItems[$category->getId()])) {
                    continue; // не посадкова: noindex, у карті сайту їй не місце
                }
                $add(
                    'sv_category',
                    static fn (string $l): array => ['category' => $category->slug($l)],
                    $newest($changed($category), $categoryTextDates[$category->getId()] ?? null),
                    $category,
                );
            }
        }
        foreach ($siteItems as $item) {
            $add(
                'sv_service',
                fn (string $l): array => $this->catalogUrls->itemParams($item, $l),
                $newest($item->getUpdatedAt(), $textDates[$item->getId()] ?? null),
                $item,
            );
        }
        // сайт без районів — порожнього /districts/ у карті не показуємо
        $districts = $this->districts->findActiveByCity($city);
        if ($districts !== []) {
            $add('sv_districts', [], $newestOf($districts));
        }
        foreach ($districts as $district) {
            $add('sv_district', static fn (string $l): array => ['slug' => $district->slug($l)], $district->getUpdatedAt(), $district);
        }
        // Блогу може не бути зовсім - порожнього розділу в карті сайту не показуємо.
        // На міських хостах блог теж не показуємо: статті там ті самі, що на
        // апексі, і canonical веде саме туди. Карта має містити лише ті адреси,
        // які ми хочемо бачити в індексі.
        // Усі статті, але адреси — лише мовами, у яких стаття існує (en може не мати uk-слага).
        $posts = [];
        $blogLocales = [];
        foreach ($this->container->get(SiteLocales::class)->enabled($site) as $l) {
            foreach ($this->posts->findPublishedBySite($site, 50, $l) as $post) {
                $posts[(int) $post->getId()] = $post;
                $blogLocales[$l] = true;
            }
        }
        $apex = $this->cities->findActiveApex($site);
        $blogBelongsHere = $apex === null || $apex->getId() === $city->getId();
        if ($posts !== [] && $blogBelongsHere) {
            $published = array_reduce($posts, static fn ($date, $post) => $newest($date, $post->getPublishedAt()), null);
            $add('sv_blog', static fn (string $l): ?array => isset($blogLocales[$l]) ? [] : null, $published);
            foreach ($posts as $post) {
                $add('sv_blog_post', static fn (string $l): ?array => $post->existsIn($l) ? ['slug' => $post->slug($l)] : null, $post->getPublishedAt());
            }
        }
        // «Про нас»: текст бренду; місто з власним текстом — новіша з двох дат
        $ownAbout = $city->aboutFor($this->container->get(SiteLocales::class)->default()) !== null;
        $add(
            'sv_about',
            static fn (string $l): ?array => $site->aboutFor($l) !== null || $city->aboutFor($l) !== null ? [] : null,
            $ownAbout ? $newest($siteDate, $cityDate) : $siteDate,
        );
        // сторінки app.pages, видимі в цьому місті (своя карта на кожен хост, як у послуг)
        if ($this->getParameter('app.pages')) {
            foreach ($this->pages->findLiveBySite($site, $city) as $page) {
                // noindex (своя чи батька) і «не в карті» — поза картою
                if (!$page->isMapped()) {
                    continue;
                }
                $add('sv_page', static fn (string $l): array => ['path' => $page->path($l)], $page->getUpdatedAt(), $page);
            }
        }
        // калькулятор є, де він наповнений (типи прибирання або позиції прайсу з in_calculator); адреса — дійсна для режиму
        if ($this->calculator->isAvailable($site, $city)) {
            $add('sv_calculator');
        }
        $add('sv_faq', [], $cityDate);
        $add('sv_contacts', [], $cityDate);
        $add('sv_reviews', [], $newestOf($this->reviews->findActiveByCity($city, 100)));
        // юридичні сторінки є завжди: текст з адмінки або типовий текст рушія; на неапексних містах пак може
        // закрити їх від індексу (app.legal_noindex_cities) — тоді й у карті їм не місце
        if (!$this->getParameter('app.legal_noindex_cities') || $apex === null || $apex->getId() === $city->getId()) {
            $locales = $this->container->get(SiteLocales::class);
            foreach (['privacy' => 'sv_privacy', 'terms' => 'sv_terms'] as $field => $route) {
                $add($route, static fn (string $l): ?array => $locales->legalLocale($site, $field, $l) !== null ? [] : null, $siteDate);
            }
        }

        $response = $this->render('services/sitemap.xml.twig', ['entries' => $entries]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * /favicon.ico від кореня (браузери й боти просять його без <link rel="icon">): 301 на app.theme.favicon_ico паку
     * з ?v=; не задано в темі — 404, як і раніше.
     */
    #[Route(path: '/favicon.ico', name: 'sv_favicon', priority: 10)]
    public function favicon(\App\ServiceSite\SiteTheme $theme): Response
    {
        $url = $theme->url('favicon_ico');
        if ($url === null || str_starts_with($url, '/favicon.ico')) {
            throw $this->createNotFoundException();
        }

        return $this->redirect($url, 301);
    }

    #[Route(path: '/robots.txt', name: 'sv_robots', priority: 10)]
    public function robots(Request $request): Response
    {
        // хост без тенанта (admin.*) ботам не потрібен взагалі
        if (!$request->attributes->has('service_city')) {
            return new Response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if ($this->serviceCity($request)->isNoindex()) {
            return new Response("User-agent: *
Disallow: /
", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /lead',
            'Disallow: /application/',
            ...array_map(fn (string $l): string => 'Disallow: ' . $this->generateUrl('sv_thanks', ['_locale' => $l]), $this->container->get(SiteLocales::class)->deployment()),
            'Disallow: /connect/',
            'Disallow: /login',
            '',
            'Sitemap: ' . $request->getSchemeAndHttpHost() . '/sitemap.xml',
            '',
        ];

        $response = new Response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
