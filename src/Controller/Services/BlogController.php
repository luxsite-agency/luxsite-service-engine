<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Repository\ServiceCityRepository;
use App\Repository\ServicePostRepository;
use App\ServiceSite\ServiceCityAware;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Блог сервісного сайту. priority+condition перекривають існуючий /blog
 * міських сайтів ТІЛЬКИ на сервісних хостах.
 */
final class BlogController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly ServicePostRepository $posts,
        private readonly ServiceCityRepository $cities,
    ) {
    }

    #[Route(
        path: ['uk' => '/%app.blog_prefix%/', 'ru' => '/ru/%app.blog_prefix%/'],
        name: 'sv_blog',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function index(Request $request): Response
    {
        return $this->list($request, 1);
    }

    /**
     * Сторінки списку з другої: /blog/page/2/. Сторінка 1 має єдину адресу /blog/ (301), за межами — 404.
     * Розмір сторінки — app.blog_per_page (пак; 0 = усі на одній). У sitemap лише /blog/.
     */
    #[Route(
        path: ['uk' => '/%app.blog_prefix%/page/{page}/', 'ru' => '/ru/%app.blog_prefix%/page/{page}/'],
        name: 'sv_blog_page',
        requirements: ['page' => '[1-9]\\d*'],
        priority: 11,
        condition: "request.attributes.has('service_city')",
    )]
    public function page(Request $request, int $page): Response
    {
        if ($page === 1) {
            return $this->redirectToRoute('sv_blog', ['_locale' => $request->getLocale()], 301);
        }

        return $this->list($request, $page);
    }

    private function list(Request $request, int $page): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);
        $perPage = max(0, (int) $this->getParameter('app.blog_per_page'));
        $total = $this->posts->countPublishedBySite($site, $request->getLocale());
        $pages = $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1;
        if ($page > $pages) {
            throw $this->createNotFoundException('Такої сторінки блогу немає.');
        }
        $altUrls = $page === 1 ? $this->altUrls('sv_blog') : $this->altUrls('sv_blog_page', ['page' => (string) $page]);

        return $this->render('services/blog/index.html.twig', [
            'site' => $site,
            'city' => $city,
            'posts' => $this->posts->findPublishedBySitePage($site, $page, $perPage, $request->getLocale()),
            'page' => $page,
            'pages' => $pages,
            'altUrls' => $altUrls,
            // блог однаковий у всіх містах, тож canonical веде на апекс
            'canonicalUrls' => $this->apexUrls($altUrls, $city, $this->cities->findActiveApex($site)),
        ]);
    }

    #[Route(
        path: ['uk' => '/%app.blog_prefix%/{slug}/', 'ru' => '/ru/%app.blog_prefix%/{slug}/'],
        name: 'sv_blog_post',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function show(Request $request, string $slug): Response
    {
        $site = $this->serviceSite($request);
        $post = $this->posts->findOnePublishedBySlug($site, $slug, $request->getLocale())
            ?? throw $this->createNotFoundException('Пост не знайдено.');

        $city = $this->serviceCity($request);
        $altUrls = $this->altUrls('sv_blog_post', static fn (string $l): array => ['slug' => $post->slug($l)]);

        return $this->render('services/blog/show.html.twig', [
            'site' => $site,
            'city' => $city,
            'post' => $post,
            'altUrls' => $altUrls,
            'canonicalUrls' => $this->apexUrls($altUrls, $city, $this->cities->findActiveApex($site)),
        ]);
    }
}
