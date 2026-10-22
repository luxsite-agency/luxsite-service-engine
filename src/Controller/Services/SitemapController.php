<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Repository\ServiceCalcTypeRepository;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceDistrictRepository;
use App\Repository\ServiceItemCityRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePostRepository;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\ServiceCityAware;
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

        // lastmod беремо з даних сторінки, а не з дати деплою: інакше
        // «змінилось усе й сьогодні» щоразу, і Google перестає вірити даті
        $textDates = $this->itemTexts->updatedAtByItem($city);
        $newest = static fn (?\DateTimeInterface $a, ?\DateTimeInterface $b): ?\DateTimeInterface
            => $a === null ? $b : ($b === null ? $a : ($a > $b ? $a : $b));

        $add('sv_home', [], $city->getUpdatedAt());
        $add('sv_services');
        // у схемах nested і root у карті є й сторінки категорій
        // послуги, що надаються в цьому місті (sitemap — на хост міста); категорії без них — поза картою
        $siteItems = $this->items->findActiveBySite($site, $city);
        $catsWithItems = [];
        foreach ($siteItems as $item) {
            $catsWithItems[$item->getCategory()->getId()] = true;
        }
        if ($this->catalogUrls->isNested()) {
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
        $posts = $this->posts->findPublishedBySite($site);
        $apex = $this->cities->findActiveApex($site);
        $blogBelongsHere = $apex === null || $apex->getId() === $city->getId();
        if ($posts !== [] && $blogBelongsHere) {
            $add('sv_blog');
            foreach ($posts as $post) {
                $add('sv_blog_post', static fn (string $l): array => ['slug' => $post->slug($l)], $post->getPublishedAt());
            }
        }
        if ($site->getAboutUk() !== null) {
            $add('sv_about');
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

        $response = $this->render('services/sitemap.xml.twig', ['entries' => $entries]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
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
