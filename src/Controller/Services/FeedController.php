<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Entity\ServicePost;
use App\Entity\ServicePostCategory;
use App\Repository\ServiceCityRepository;
use App\Repository\ServicePostCategoryRepository;
use App\Repository\ServicePostRepository;
use App\ServiceSite\BrandSettings;
use App\ServiceSite\ServiceCityAware;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RSS 2.0 контентного сайту (25.10.2026): /feed/ — свіжі статті бренду, стрічка рубрики — /<рубрика>/feed/ при
 * category_url: root (BrandRouter підміняє sv_blog_category_feed), інакше /<блог>/category/<рубрика>/feed/. Читалки й
 * агрегатори досі тягнули /feed/ старих WordPress-сайтів (тисячі 404 на день). Посилання в стрічці — канонічні (апекс),
 * guid стабільний (не залежить від слагу), обкладинка — enclosure + media:content. Кеш — 5 хвилин.
 */
final class FeedController extends AbstractController
{
    use ServiceCityAware;

    private const LIMIT = 30;
    private const IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    public function __construct(
        private readonly ServicePostRepository $posts,
        private readonly ServicePostCategoryRepository $categories,
        private readonly ServiceCityRepository $cities,
        private readonly BrandSettings $brandSettings,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[Route(
        path: ['uk' => '/feed/', 'ru' => '/ru/feed/'],
        name: 'sv_feed',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function site(Request $request): Response
    {
        return $this->feed($request, null);
    }

    #[Route(
        path: ['uk' => '/{_blog}/category/{slug}/feed/', 'ru' => '/ru/{_blog}/category/{slug}/feed/'],
        name: 'sv_blog_category_feed',
        requirements: ['_blog' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+'],
        priority: 12,
        defaults: ['_blog' => '%app.blog_prefix%'],
        condition: "request.attributes.has('service_city') and params['_blog'] == service('brand_routes').blog(request)",
    )]
    public function category(Request $request, string $slug): Response
    {
        // бренд з рубриками від кореня: одна адреса стрічки — /<рубрика>/feed/ (як і сторінки рубрики)
        if ($this->brandSettings->categoryRoot($this->serviceSite($request))) {
            return $this->redirectToRoute('sv_blog_category_feed', ['slug' => $slug, '_locale' => $request->getLocale()], 301);
        }

        return $this->categoryFeed($request, $slug);
    }

    #[Route(
        path: ['uk' => '/{slug}/feed/', 'ru' => '/ru/{slug}/feed/'],
        name: 'sv_blog_category_root_feed',
        requirements: ['slug' => '[a-z0-9-]+'],
        priority: 2,
        condition: "request.attributes.has('service_city') and service('brand_routes').rootCategory(request, params)",
    )]
    public function categoryRoot(Request $request, string $slug): Response
    {
        return $this->categoryFeed($request, $slug);
    }

    private function categoryFeed(Request $request, string $slug): Response
    {
        $category = $this->categories->findOneActiveBySlug($this->serviceSite($request), $slug, $request->getLocale())
            ?? throw $this->createNotFoundException('Рубрику не знайдено.');

        return $this->feed($request, $category);
    }

    private function feed(Request $request, ?ServicePostCategory $category): Response
    {
        $site = $this->serviceSite($request);
        $locale = $request->getLocale();
        $apex = $this->cities->findActiveApex($site);
        $host = $apex?->getHost() ?: $request->getHost();
        $base = $request->getScheme() . '://' . $host;

        $items = [];
        $updated = null;
        foreach ($this->posts->findPublishedFiltered($site, $locale, $category, null, 1, self::LIMIT) as $post) {
            $items[] = $this->item($post, $locale, $base, $host);
            $at = $post->getUpdatedAt() ?? $post->getPublishedAt();
            if ($at !== null && ($updated === null || $at > $updated)) {
                $updated = $at;
            }
        }

        $home = $base . $this->generateUrl('sv_home', ['_locale' => $locale]);
        $response = $this->render('services/content/feed.xml.twig', [
            'site' => $site,
            'category' => $category,
            'items' => $items,
            'channelLink' => $category !== null ? $base . $this->generateUrl('sv_blog_category', ['slug' => $category->slug($locale), '_locale' => $locale]) : $home,
            'selfUrl' => $base . $request->getPathInfo(),
            'lastBuild' => ($updated ?? new \DateTimeImmutable())->format(\DATE_RSS),
        ]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    /** @return array<string, string|int|null> */
    private function item(ServicePost $post, string $locale, string $base, string $host): array
    {
        $lead = trim(html_entity_decode(strip_tags((string) $post->loc('lead', $locale)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        if ($lead === '') {
            $text = trim((string) preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags((string) $post->loc('desc', $locale)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
            $lead = mb_strlen($text) > 300 ? rtrim(mb_substr($text, 0, 300)) . '…' : $text;
        }
        $image = null;
        $imageType = null;
        $imageLength = null;
        if ($post->getImageName() !== null) {
            $image = $base . '/media/service_post/' . rawurlencode($post->getImageName());
            $imageType = self::IMAGE_TYPES[strtolower(pathinfo($post->getImageName(), \PATHINFO_EXTENSION))] ?? 'image/jpeg';
            $file = $this->projectDir . '/public/media/service_post/' . $post->getImageName();
            $imageLength = is_file($file) ? (int) filesize($file) : null;
        }
        $cat = $post->getCategory();

        return [
            'title' => (string) $post->loc('title', $locale),
            'link' => $base . $this->generateUrl('sv_blog_post', ['slug' => $post->slug($locale), '_locale' => $locale]),
            // guid не залежить від слагу: перейменування статті не дублює її в читалках
            'guid' => $host . '-post-' . $post->getId(),
            'pubDate' => ($post->getPublishedAt() ?? new \DateTimeImmutable())->format(\DATE_RSS),
            'description' => $lead,
            'category' => $cat !== null && $cat->existsIn($locale) ? (string) $cat->loc('title', $locale) : null,
            'image' => $image,
            'imageType' => $imageType,
            'imageLength' => $imageLength,
        ];
    }
}
