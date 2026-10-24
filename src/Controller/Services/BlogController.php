<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Entity\ServiceAuthor;
use App\Entity\ServicePostCategory;
use App\Entity\ServiceSite;
use App\Repository\ServiceAuthorRepository;
use App\Repository\ServiceCityRepository;
use App\Repository\ServicePostCategoryRepository;
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
        private readonly ServicePostCategoryRepository $categories,
        private readonly ServiceAuthorRepository $authors,
    ) {
    }

    #[Route(
        path: ['uk' => '/{_blog}/', 'ru' => '/ru/{_blog}/'],
        name: 'sv_blog',
        requirements: ['_blog' => '[a-z0-9-]+'],
        priority: 10,
        defaults: ['_blog' => '%app.blog_prefix%'],
        condition: "request.attributes.has('service_city') and params['_blog'] == service('brand_routes').blog(request)",
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
        path: ['uk' => '/{_blog}/page/{page}/', 'ru' => '/ru/{_blog}/page/{page}/'],
        name: 'sv_blog_page',
        requirements: ['_blog' => '[a-z0-9-]+', 'page' => '[1-9]\\d*'],
        priority: 11,
        defaults: ['_blog' => '%app.blog_prefix%'],
        condition: "request.attributes.has('service_city') and params['_blog'] == service('brand_routes').blog(request)",
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
        if ($site->isContent()) {
            return $this->contentList($request, $site, $page, null, null);
        }
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
        path: ['uk' => '/{_blog}/{slug}/', 'ru' => '/ru/{_blog}/{slug}/'],
        name: 'sv_blog_post',
        requirements: ['_blog' => '[a-z0-9-]+'],
        priority: 10,
        defaults: ['_blog' => '%app.blog_prefix%'],
        condition: "request.attributes.has('service_city') and params['_blog'] == service('brand_routes').blog(request)",
    )]
    public function show(Request $request, string $slug): Response
    {
        $site = $this->serviceSite($request);
        $post = $this->posts->findOnePublishedBySlug($site, $slug, $request->getLocale())
            ?? throw $this->createNotFoundException('Пост не знайдено.');

        $city = $this->serviceCity($request);
        // мовні версії — лише ті, якими стаття існує (hreflang і перемикач без 404)
        $altUrls = array_intersect_key(
            $this->altUrls('sv_blog_post', static fn (string $l): array => ['slug' => $post->slug($l) ?: '-']),
            array_flip(array_filter($this->container->get(\App\ServiceSite\SiteLocales::class)->enabled($site), $post->existsIn(...))),
        );

        return $this->render($site->isContent() ? 'services/content/post.html.twig' : 'services/blog/show.html.twig', [
            'site' => $site,
            'city' => $city,
            'post' => $post,
            'related' => $site->isContent() ? $this->posts->findRelated($site, $post, $request->getLocale(), 3) : [],
            'altUrls' => $altUrls,
            'canonicalUrls' => $this->apexUrls($altUrls, $city, $this->cities->findActiveApex($site)),
        ]);
    }

    /** Рубрика контентного сайту: /<блог>/category/<slug>/ */
    #[Route(
        path: ['uk' => '/{_blog}/category/{slug}/', 'ru' => '/ru/{_blog}/category/{slug}/'],
        name: 'sv_blog_category',
        requirements: ['_blog' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+'],
        priority: 11,
        defaults: ['_blog' => '%app.blog_prefix%'],
        condition: "request.attributes.has('service_city') and params['_blog'] == service('brand_routes').blog(request)",
    )]
    public function category(Request $request, string $slug): Response
    {
        return $this->categoryPage($request, $slug, 1);
    }

    #[Route(
        path: ['uk' => '/{_blog}/category/{slug}/page/{page}/', 'ru' => '/ru/{_blog}/category/{slug}/page/{page}/'],
        name: 'sv_blog_category_page',
        requirements: ['_blog' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+', 'page' => '[1-9]\\d*'],
        priority: 12,
        defaults: ['_blog' => '%app.blog_prefix%'],
        condition: "request.attributes.has('service_city') and params['_blog'] == service('brand_routes').blog(request)",
    )]
    public function categoryPaged(Request $request, string $slug, int $page): Response
    {
        if ($page === 1) {
            return $this->redirectToRoute('sv_blog_category', ['slug' => $slug, '_locale' => $request->getLocale()], 301);
        }

        return $this->categoryPage($request, $slug, $page);
    }

    /** Автор статей контентного сайту: /author/<slug>/ */
    #[Route(
        path: ['uk' => '/author/{slug}/', 'ru' => '/ru/author/{slug}/'],
        name: 'sv_author',
        requirements: ['slug' => '[a-z0-9-]+'],
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function author(Request $request, string $slug): Response
    {
        $site = $this->serviceSite($request);
        $author = $this->authors->findOneActiveBySlug($site, $slug) ?? throw $this->createNotFoundException('Автора не знайдено.');

        return $this->contentList($request, $site, 1, null, $author);
    }

    private function categoryPage(Request $request, string $slug, int $page): Response
    {
        $site = $this->serviceSite($request);
        $category = $this->categories->findOneActiveBySlug($site, $slug, $request->getLocale())
            ?? throw $this->createNotFoundException('Рубрику не знайдено.');

        return $this->contentList($request, $site, $page, $category, null);
    }

    /**
     * Список статей контентного сайту: увесь блог, рубрика або автор (усі статті автора — однією сторінкою).
     * Canonical — на апекс, як і у блогу сервісного сайту.
     */
    private function contentList(Request $request, ServiceSite $site, int $page, ?ServicePostCategory $category, ?ServiceAuthor $author): Response
    {
        $city = $this->serviceCity($request);
        $locale = $request->getLocale();
        $perPage = $author !== null ? 0 : max(0, (int) $this->getParameter('app.blog_per_page'));
        $total = $this->posts->countPublishedFiltered($site, $locale, $category, $author);
        $pages = $perPage > 0 ? max(1, (int) ceil($total / $perPage)) : 1;
        if ($page > $pages) {
            throw $this->createNotFoundException('Такої сторінки немає.');
        }
        if ($category !== null) {
            $altUrls = $page === 1
                ? $this->altUrls('sv_blog_category', static fn (string $l): array => ['slug' => $category->slug($l) ?: '-'])
                : $this->altUrls('sv_blog_category_page', static fn (string $l): array => ['slug' => $category->slug($l) ?: '-', 'page' => (string) $page]);
            $altUrls = array_intersect_key($altUrls, array_flip(array_filter(array_keys($altUrls), $category->existsIn(...))));
        } elseif ($author !== null) {
            $altUrls = $this->altUrls('sv_author', ['slug' => $author->getSlug()]);
        } else {
            $altUrls = $page === 1 ? $this->altUrls('sv_blog') : $this->altUrls('sv_blog_page', ['page' => (string) $page]);
        }

        return $this->render('services/content/list.html.twig', [
            'site' => $site,
            'city' => $city,
            'posts' => $this->posts->findPublishedFiltered($site, $locale, $category, $author, $page, $perPage),
            'category' => $category,
            'author' => $author,
            'categories' => $this->categories->findActiveBySite($site, $locale),
            'page' => $page,
            'pages' => $pages,
            'altUrls' => $altUrls,
            'canonicalUrls' => $this->apexUrls($altUrls, $city, $this->cities->findActiveApex($site)),
        ]);
    }
}
