<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceDistrictRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePriceRepository;
use App\Repository\ServiceReviewRepository;
use App\Repository\ServiceTeamMemberRepository;
use App\Repository\ServiceWorkRepository;
use App\ServiceSite\ServiceCityAware;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Головна сервісного сайту: інтро міста + послуги по категоріях + відгуки +
 * команда + райони + FAQ (блоки з БД, як на сайтах з Joomla-імпортом).
 * Всі роути профілю: priority 10 + condition — див. ServiceHostSubscriber.
 */
final class HomeController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly ServiceCategoryRepository $categories,
        private readonly ServiceItemRepository $items,
        private readonly ServicePriceRepository $prices,
        private readonly ServiceReviewRepository $reviews,
        private readonly \App\ServiceSite\ReviewsRating $reviewsRating,
        private readonly ServiceTeamMemberRepository $team,
        private readonly ServiceDistrictRepository $districts,
        private readonly ServiceWorkRepository $works,
    ) {
    }

    #[Route(
        path: ['uk' => '/', 'ru' => '/ru/'],
        name: 'sv_home',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function index(Request $request): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);

        $itemsByCategory = [];
        foreach ($this->items->findActiveBySite($site, $city) as $item) {
            $itemsByCategory[$item->getCategory()->getId()][] = $item;
        }

        // Прайс міста, згрупований по послугах (таби)
        $pricesByItem = [];
        foreach ($this->prices->findActiveByCity($city) as $price) {
            $itemId = $price->getItem()->getId();
            $pricesByItem[$itemId] ??= ['item' => $price->getItem(), 'rows' => []];
            $pricesByItem[$itemId]['rows'][] = $price;
        }

        return $this->render('services/home.html.twig', [
            'site' => $site,
            'city' => $city,
            // категорії без жодної послуги в цьому місті не показуємо
            'categories' => array_values(array_filter($this->categories->findActiveBySite($site), static fn ($c) => isset($itemsByCategory[$c->getId()]))),
            'itemsByCategory' => $itemsByCategory,
            'pricesByItem' => $pricesByItem,
            // 4 = один ряд карток, без переносу (решта - на /reviews/)
            'reviews' => $this->reviews->findActiveByCity($city, 4),
            // у JSON-LD — лише ті самі відгуки, що видно на сторінці (правило власника 11.10.2026; решта — на /reviews/)
            'reviewsSchema' => $this->reviews->findActiveByCity($city, 4),
            // загальна оцінка (рядок + aggregateRating): з віджета gbp-manager, якщо підключений, інакше власна (ReviewsRating)
            'reviewsAggregate' => $this->reviewsRating->aggregate($city),
            // команда на головній — лише якщо пак так задав (app.team_pages містить home; типово [about])
            'team' => \in_array('home', array_map('strval', (array) $this->getParameter('app.team_pages')), true) ? $this->team->findActiveByCity($city) : [],
            // чипи районів: пак app.home_districts {slug міста: [slug_uk…] | '*'}; порожньо = лише primary-райони
            'homeDistricts' => $this->homeDistricts($city),
            // FAQ головної: перші N питань (пак: app.home_faq_limit, 0 = усі)
            'homeFaq' => array_slice($city->loc('faq', $request->getLocale()) ?? [], 0, ((int) $this->getParameter('app.home_faq_limit')) ?: null),
            // скільки питань у міста всього: якщо ліміт обрізав — під акордеоном посилання «Усі питання» на /faq/
            'faqTotal' => \count($city->loc('faq', $request->getLocale()) ?? []),
            // наскрізна галерея: власні кадри з усіх зйомок, по два ряди
            'works' => $this->works->findForCity($city, 8),
            'altUrls' => $this->altUrls('sv_home'),
        ]);
    }

    /**
     * Райони для блоку на головній. app.home_districts (пак): мапа slug міста → список slug_uk у потрібному порядку
     * (популярні за Search Console) або '*' = усі активні. Невідомі/неактивні slug пропускаються. Без ключа — primary-райони.
     *
     * @return \App\Entity\ServiceDistrict[]
     */
    private function homeDistricts(\App\Entity\ServiceCity $city): array
    {
        $cfg = (array) $this->getParameter('app.home_districts');
        $want = $cfg[$city->getSlug()] ?? null;
        if ($want === null || $want === [] || $want === '') {
            return $this->districts->findPrimaryByCity($city);
        }
        $all = $this->districts->findActiveByCity($city);
        if ($want === '*') {
            return $all;
        }
        $bySlug = [];
        foreach ($all as $d) {
            $bySlug[$d->getSlugUk()] = $d;
        }
        $out = [];
        foreach ((array) $want as $slug) {
            if (isset($bySlug[(string) $slug])) {
                $out[] = $bySlug[(string) $slug];
            }
        }

        return $out;
    }
}
