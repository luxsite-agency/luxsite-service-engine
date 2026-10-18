<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Repository\ServiceReviewRepository;
use App\Repository\ServiceTeamMemberRepository;
use App\ServiceSite\ServiceCityAware;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Статичні сторінки сервісного сайту: /about/ (текст бренду), /faq/ (FAQ міста),
 * /contacts/ (контакти міста + форма), /reviews/ (всі відгуки міста).
 */
final class PageController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly ServiceReviewRepository $reviews,
        private readonly \App\ServiceSite\ReviewsRating $reviewsRating,
        private readonly ServiceTeamMemberRepository $team,
    ) {
    }

    #[Route(
        path: ['uk' => '/about/', 'ru' => '/ru/about/'],
        name: 'sv_about',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function about(Request $request): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);
        // сторінка прихована, поки нема тексту «Про нас» — ні спільного (site.aboutUk), ні міського (city.aboutUk)
        if ($site->getAboutUk() === null && $city->getAboutUk() === null) {
            throw $this->createNotFoundException('Сторінку ще не наповнено.');
        }

        return $this->render('services/page/about.html.twig', [
            'site' => $site,
            'city' => $city,
            'team' => $this->teamFor('about', $city),
            'altUrls' => $this->altUrls('sv_about'),
        ]);
    }

    #[Route(
        path: ['uk' => '/faq/', 'ru' => '/ru/faq/'],
        name: 'sv_faq',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function faq(Request $request): Response
    {
        return $this->render('services/page/faq.html.twig', [
            'site' => $this->serviceSite($request),
            'city' => $this->serviceCity($request),
            'altUrls' => $this->altUrls('sv_faq'),
        ]);
    }

    /** Сторінка-підтвердження після заявки (noindex; ціль конверсії для GTM) */
    #[Route(
        path: ['uk' => '/thank-you/', 'ru' => '/ru/thank-you/'],
        name: 'sv_thanks',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function thanks(Request $request): Response
    {
        return $this->render('services/page/thanks.html.twig', [
            'site' => $this->serviceSite($request),
            'city' => $this->serviceCity($request),
            'altUrls' => $this->altUrls('sv_thanks'),
        ]);
    }

    #[Route(
        path: ['uk' => '/contacts/', 'ru' => '/ru/contacts/'],
        name: 'sv_contacts',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function contacts(Request $request): Response
    {
        $city = $this->serviceCity($request);

        return $this->render('services/page/contacts.html.twig', [
            'site' => $this->serviceSite($request),
            'city' => $city,
            'team' => $this->teamFor('contacts', $city),
            'altUrls' => $this->altUrls('sv_contacts'),
        ]);
    }

    #[Route(
        path: ['uk' => '/reviews/', 'ru' => '/ru/reviews/'],
        name: 'sv_reviews',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function reviews(Request $request): Response
    {
        $city = $this->serviceCity($request);

        return $this->render('services/page/reviews.html.twig', [
            'site' => $this->serviceSite($request),
            'city' => $city,
            'reviews' => $this->reviews->findActiveByCity($city, 100),
            // загальна оцінка (рядок + aggregateRating): з віджета gbp-manager, якщо підключений, інакше власна (ReviewsRating)
            'reviewsAggregate' => $this->reviewsRating->aggregate($city, true),
            'altUrls' => $this->altUrls('sv_reviews'),
        ]);
    }

    /** Політика конфіденційності: обовязкова для Google OAuth і форм заявок. */
    #[Route(
        path: ['uk' => '/privacy-policy/', 'ru' => '/ru/privacy-policy/'],
        name: 'sv_privacy',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function privacy(Request $request): Response
    {
        return $this->legal($request, 'privacy', 'sv_privacy');
    }

    /** Умови надання послуг (публічна оферта). */
    #[Route(
        path: ['uk' => '/terms-of-service/', 'ru' => '/ru/terms-of-service/'],
        name: 'sv_terms',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function terms(Request $request): Response
    {
        return $this->legal($request, 'terms', 'sv_terms');
    }

    /**
     * Спільний рендер юридичних сторінок: текст живе в бренді (ServiceSite, адмінка);
     * поки поле порожнє - типовий текст рушія (services/page/privacy|terms.html.twig,
     * типовий для перших сайтів), щоб сторінка і посилання у футері були завжди.
     */
    private function legal(Request $request, string $field, string $route): Response
    {
        $site = $this->serviceSite($request);
        $text = $site->loc($field, $request->getLocale());
        if ($text === null || trim($text) === '') {
            return $this->render('services/page/' . $field . '.html.twig', [
                'site' => $site,
                'city' => $this->serviceCity($request),
                'altUrls' => $this->altUrls($route),
            ]);
        }

        return $this->render('services/page/legal.html.twig', [
            'site' => $site,
            'city' => $this->serviceCity($request),
            'heading' => 'nav.' . $field,
            'text' => $text,
            'altUrls' => $this->altUrls($route),
        ]);
    }

    /**
     * Команда міста для сторінки, якщо пак її там показує (app.team_pages: [about] типово; about|contacts).
     * Правило власника 11.10.2026: команда — на «Про нас»; до того (01.09) блок жив лише на контактах.
     *
     * @return \App\Entity\ServiceTeamMember[]
     */
    private function teamFor(string $page, \App\Entity\ServiceCity $city): array
    {
        $pages = array_map('strval', (array) $this->getParameter('app.team_pages'));

        return \in_array($page, $pages, true) ? $this->team->findActiveByCity($city) : [];
    }
}
