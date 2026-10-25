<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Entity\ServicePostTag;
use App\Repository\ServiceAuthorRepository;
use App\Repository\ServiceCalcTypeRepository;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceDistrictRepository;
use App\Repository\ServiceItemCityRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePageRepository;
use App\Repository\ServicePostCategoryRepository;
use App\Repository\ServicePostRepository;
use App\Repository\ServicePostTagRepository;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\ServiceCityAware;
use App\ServiceSite\SiteLocales;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Автогенерована карта сайту тенанта: збирається з БД на кожен запит,
 * тож нові послуги/райони/статті потрапляють у неї одразу після публікації.
 * URL — тільки індексовані сторінки (без /lead, /thank-you, /connect).
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
        private readonly ServiceCalcTypeRepository $calcTypes,
        private readonly ServicePageRepository $pages,
        private readonly ServicePostCategoryRepository $postCategories,
        private readonly ServiceAuthorRepository $authors,
        private readonly ServicePostTagRepository $postTags,
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
        $add = function (string $route, array|\Closure $params = [], ?\DateTimeInterface $lastmod = null) use (&$entries): void {
            $entries[] = ['urls' => $this->altUrls($route, $params), 'lastmod' => $lastmod];
        };

        if ($site->isContent()) {
            return $this->xml($this->contentEntries($site, $city));
        }

        // lastmod беремо з даних сторінки, а не з дати деплою: інакше
        // «змінилось усе й сьогодні» щоразу, і Google перестає вірити даті
        $textDates = $this->itemTexts->updatedAtByItem($city);
        $newest = static fn (?\DateTimeInterface $a, ?\DateTimeInterface $b): ?\DateTimeInterface
            => $a === null ? $b : ($b === null ? $a : ($a > $b ? $a : $b));

        $add('sv_home', [], $city->getUpdatedAt());
        $add('sv_services');
        // у схемах nested, root і slug у карті є й сторінки категорій
        // послуги, що надаються в цьому місті (sitemap — на хост міста); категорії без них — поза картою
        $siteItems = $this->items->findActiveBySite($site, $city);
        $catsWithItems = [];
        foreach ($siteItems as $item) {
            $catsWithItems[$item->getCategory()->getId()] = true;
        }
        if ($this->catalogUrls->hasCategoryPages()) {
            foreach ($this->categories->findActiveBySite($site) as $category) {
                if (!$category->isLanding() || !isset($catsWithItems[$category->getId()])) {
                    continue; // не посадкова: noindex, у карті сайту їй не місце
                }
                $add('sv_category', static fn (string $l): array => ['category' => $category->slug($l)]);
            }
        }
        foreach ($siteItems as $item) {
            $add(
                'sv_service',
                fn (string $l): array => $this->catalogUrls->itemParams($item, $l),
                $newest($item->getUpdatedAt(), $textDates[$item->getId()] ?? null),
            );
        }
        // сайт без районів — порожнього /districts/ у карті не показуємо
        $districts = $this->districts->findActiveByCity($city);
        if ($districts !== []) {
            $add('sv_districts');
        }
        foreach ($districts as $district) {
            $add('sv_district', static fn (string $l): array => ['slug' => $district->slug($l)], $district->getUpdatedAt());
        }
        // Блогу може не бути зовсім - порожнього розділу в карті сайту не показуємо.
        // На міських хостах блог теж не показуємо: статті там ті самі, що на
        // апексі, і canonical веде саме туди. Карта має містити лише ті адреси,
        // які ми хочемо бачити в індексі.
        // статті, що існують мовою без префікса (для uk/ru — усі з uk-слагом, як і раніше)
        $posts = $this->posts->findPublishedBySite($site, 50, $this->container->get(SiteLocales::class)->default());
        $apex = $this->cities->findActiveApex($site);
        $blogBelongsHere = $apex === null || $apex->getId() === $city->getId();
        if ($posts !== [] && $blogBelongsHere) {
            $add('sv_blog');
            foreach ($posts as $post) {
                $add('sv_blog_post', static fn (string $l): array => ['slug' => $post->slug($l)], $post->modifiedAt());
            }
        }
        if ($site->aboutFor($this->container->get(SiteLocales::class)->default()) !== null) {
            $add('sv_about');
        }
        // сторінки app.pages, видимі в цьому місті (своя карта на кожен хост, як у послуг)
        if ($this->getParameter('app.pages')) {
            foreach ($this->pages->findLiveBySite($site, $city) as $page) {
                $add('sv_page', static fn (string $l): array => ['path' => $page->path($l)], $page->getUpdatedAt());
            }
        }
        // калькулятор є лише в брендів, де він наповнений (типи в БД)
        if ($this->calcTypes->findActiveBySite($site) !== []) {
            $add('sv_calculator');
        }
        $add('sv_faq');
        $add('sv_contacts');
        $add('sv_reviews');
        // юридичні сторінки є завжди: текст з адмінки або типовий текст рушія
        $add('sv_privacy');
        $add('sv_terms');

        return $this->xml($entries);
    }

    /** @param list<array{urls: array<string, string>, lastmod: ?\DateTimeInterface}> $entries */
    private function xml(array $entries): Response
    {
        $response = $this->render('services/sitemap.xml.twig', ['entries' => $entries]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }

    /**
     * Карта контентного сайту (ServiceSite.mode = content): головна, блог, рубрики, теги й автори зі статтями, статті, «Про нас»,
     * контакти, юридичні сторінки. Сторінки, що існують не всіма мовами бренду (статті, рубрики), — лише своїми мовами.
     * Блог і статті — лише на апексі (як у сервісного сайту).
     *
     * @return list<array{urls: array<string, string>, lastmod: ?\DateTimeInterface}>
     */
    private function contentEntries(\App\Entity\ServiceSite $site, \App\Entity\ServiceCity $city): array
    {
        $locales = $this->container->get(SiteLocales::class)->enabled($site);
        $default = $locales[0] ?? 'en';
        $entries = [];
        $add = function (string $route, array|\Closure $params = [], ?\DateTimeInterface $lastmod = null, ?\Closure $existsIn = null) use (&$entries): void {
            $urls = $this->altUrls($route, $params);
            if ($existsIn !== null) {
                $urls = array_filter($urls, $existsIn, \ARRAY_FILTER_USE_KEY);
            }
            if ($urls !== []) {
                $entries[] = ['urls' => $urls, 'lastmod' => $lastmod];
            }
        };

        $add('sv_home', [], $city->getUpdatedAt());
        $apex = $this->cities->findActiveApex($site);
        if ($apex === null || $apex->getId() === $city->getId()) {
            $posts = $this->posts->findPublishedBySite($site, 5000, $default);
            if ($posts !== []) {
                $add('sv_blog');
            }
            $withPosts = ['cat' => [], 'author' => []];
            foreach ($posts as $post) {
                $add('sv_blog_post', static fn (string $l): array => ['slug' => $post->slug($l) ?: '-'], $post->modifiedAt(), $post->existsIn(...));
                if ($post->getCategory() !== null) {
                    $withPosts['cat'][$post->getCategory()->getId()] = true;
                }
                if ($post->getAuthor() !== null) {
                    $withPosts['author'][$post->getAuthor()->getId()] = true;
                }
            }
            foreach ($this->postCategories->findActiveBySite($site, $default) as $c) {
                if (isset($withPosts['cat'][$c->getId()])) {
                    $add('sv_blog_category', static fn (string $l): array => ['slug' => $c->slug($l) ?: '-'], null, $c->existsIn(...));
                }
            }
            // теги — лише мовами, де сторінка індексується (від ServicePostTag::INDEX_MIN_POSTS статей, тобто з першої)
            $tagCounts = [];
            $indexedIds = [];
            foreach ($locales as $l) {
                $tagCounts[$l] = $this->posts->countPublishedByTag($site, $l);
                $indexedIds += array_filter($tagCounts[$l], static fn (int $n): bool => $n >= ServicePostTag::INDEX_MIN_POSTS);
            }
            // лише теги, що індексуються хоч однією мовою, а не всі теги бренду (у новинних — тисячі людей)
            $indexedTags = $indexedIds === [] ? [] : $this->postTags->findBy(['site' => $site, 'active' => true, 'id' => array_keys($indexedIds)], ['id' => 'ASC']);
            foreach ($indexedTags as $t) {
                $indexed = static fn (string $l): bool => $t->existsIn($l) && ($tagCounts[$l][$t->getId()] ?? 0) >= ServicePostTag::INDEX_MIN_POSTS;
                $add('sv_blog_tag', static fn (string $l): array => ['slug' => $t->slug($l) ?: '-'], null, $indexed);
            }
            foreach ($this->authors->findActiveBySite($site) as $a) {
                if (isset($withPosts['author'][$a->getId()])) {
                    $add('sv_author', ['slug' => $a->getSlug()]);
                }
            }
        }
        if ($site->aboutFor($default) !== null || $city->aboutFor($default) !== null) {
            $add('sv_about');
        }
        $add('sv_contacts');
        $add('sv_privacy');
        $add('sv_terms');

        return $entries;
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
            'Disallow: /thank-you/',
            'Disallow: /ru/thank-you/',
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
