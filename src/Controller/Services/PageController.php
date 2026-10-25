<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Repository\ServiceCityRepository;
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
        private readonly ServiceCityRepository $cityRepo,
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
        // сторінка прихована, поки нема тексту «Про нас» мовою сторінки — ні спільного (site), ні міського (city)
        if ($site->aboutFor($request->getLocale()) === null && $city->aboutFor($request->getLocale()) === null) {
            throw $this->createNotFoundException('Сторінку ще не наповнено.');
        }

        return $this->render($site->isContent() ? 'services/content/about.html.twig' : 'services/page/about.html.twig', [
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
        $site = $this->serviceSite($request);

        return $this->render($site->isContent() ? 'services/content/thanks.html.twig' : 'services/page/thanks.html.twig', [
            'site' => $site,
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
        if ($this->serviceSite($request)->isContent()) {
            // контентний сайт: лише форма зв'язку, без телефонів, адрес і месенджерів (правило власника 22.10.2026)
            return $this->render('services/content/contact.html.twig', [
                'site' => $this->serviceSite($request),
                'city' => $city,
                'altUrls' => $this->altUrls('sv_contacts'),
            ]);
        }

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
            // токен віджета gbp-manager для цього міста (app.gbp_reviews.cities[slug] або спільний); порожньо — форма й список рушія
            'gbpToken' => $this->reviewsRating->gbpToken($city),
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
        $city = $this->serviceCity($request);
        // текст однаковий на всіх хостах: на неапексних містах — noindex, follow (пак: app.legal_noindex_cities). Заголовок
        // X-Robots-Tag — щоб працювало й з паком/живою темою, що перекриває base.html.twig без pageNoindex.
        $noindex = $this->legalNoindex($city);
        $text = $site->loc($field, $request->getLocale());
        if ($site->isContent()) {
            // контентний сайт: свій каркас і типовий текст під форму зв'язку (без телефонів, відгуків і заявок)
            return $this->render('services/content/legal.html.twig', [
                'site' => $site,
                'city' => $this->serviceCity($request),
                'heading' => 'nav.' . $field,
                'kind' => $field,
                'text' => $text !== null && trim($text) !== '' ? $text : null,
                'altUrls' => $this->altUrls($route),
            ]);
        }
        if ($text === null || trim($text) === '') {
            $response = $this->render('services/page/' . $field . '.html.twig', [
                'site' => $site,
                'city' => $city,
                'altUrls' => $this->altUrls($route),
                'pageNoindex' => $noindex,
            ]);
        } else {
            $response = $this->render('services/page/legal.html.twig', [
                'site' => $site,
                'city' => $city,
                'heading' => 'nav.' . $field,
                'text' => $text,
                'altUrls' => $this->altUrls($route),
                'pageNoindex' => $noindex,
            ]);
        }
        if ($noindex) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }

    /** Юридична сторінка поза індексом: пак увімкнув app.legal_noindex_cities і це не апекс (сайт без активного апексу — ні). */
    private function legalNoindex(\App\Entity\ServiceCity $city): bool
    {
        if (!$this->getParameter('app.legal_noindex_cities')) {
            return false;
        }
        $apex = $this->cityRepo->findActiveApex($city->getSite());

        return $apex !== null && $apex->getId() !== $city->getId();
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
