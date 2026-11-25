<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceDistrictRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePageRepository;
use App\Repository\ServicePostRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * HTML-карта сайту для сторінки app.pages з шаблоном sitemap: каталог (категорії → послуги), сторінки, блог, фіксовані
 * розділи. Ті самі правила, що в sitemap.xml (SitemapController): лише видиме в місті й те, що індексується — без noindex
 * послуг, категорій і сторінок, без непосадкових категорій, блог лише на апексі. Адреси — відносні, мовою сторінки.
 */
final class HtmlSitemap
{
    public function __construct(
        private readonly ServiceCategoryRepository $categories,
        private readonly ServiceItemRepository $items,
        private readonly ServicePageRepository $pages,
        private readonly ServicePostRepository $posts,
        private readonly ServiceCityRepository $cities,
        private readonly ServiceDistrictRepository $districts,
        private readonly CatalogUrls $catalogUrls,
        private readonly CalculatorAvailability $calculator,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
        #[Autowire(param: 'app.pages')] private readonly bool $pagesEnabled,
        private readonly ?FixedPages $fixedPages = null,
    ) {
    }

    /**
     * @return array{
     *     catalog: list<array{title: string, url: ?string, items: list<array{title: string, url: string}>}>,
     *     pages: list<array{title: string, url: string, children: list<array{title: string, url: string}>}>,
     *     posts: list<array{title: string, url: string}>,
     *     fixed: list<array{title: string, url: string}>
     * }
     */
    public function build(ServiceSite $site, ServiceCity $city, string $locale): array
    {
        $catalog = [];
        $categoryPages = $this->catalogUrls->hasCategoryPages();
        foreach ($this->items->findActiveBySite($site, $city) as $item) {
            $cat = $item->getCategory();
            if ($item->isNoindex()) {
                continue;
            }
            $id = (int) $cat->getId();
            if (!isset($catalog[$id])) {
                $catalog[$id] = [
                    'title' => (string) $cat->loc('title', $locale),
                    'url' => $categoryPages && $cat->isLanding() && !$cat->isNoindex()
                        ? $this->path('sv_category', ['category' => $cat->slug($locale)], $locale) : null,
                    'items' => [],
                ];
            }
            $catalog[$id]['items'][] = [
                'title' => (string) $item->shortName($locale),
                'url' => $this->path('sv_service', $this->catalogUrls->itemParams($item, $locale), $locale),
            ];
        }

        $pages = [];
        if ($this->pagesEnabled) {
            $live = $this->pages->findLiveBySite($site, $city);
            foreach ($live as $page) {
                if ($page->getParent() === null && $page->isMapped()) {
                    $pages[(int) $page->getId()] = ['title' => (string) $page->loc('title', $locale), 'url' => $this->path('sv_page', ['path' => $page->path($locale)], $locale), 'children' => []];
                }
            }
            foreach ($live as $page) {
                $parentId = $page->getParent()?->getId();
                if ($parentId !== null && isset($pages[$parentId]) && $page->isMapped()) {
                    $pages[$parentId]['children'][] = ['title' => (string) $page->loc('title', $locale), 'url' => $this->path('sv_page', ['path' => $page->path($locale)], $locale)];
                }
            }
        }

        $posts = [];
        $apex = $this->cities->findActiveApex($site);
        if ($apex === null || $apex->getId() === $city->getId()) {
            foreach ($this->posts->findPublishedBySite($site, 500, $locale) as $post) {
                $posts[] = ['title' => (string) $post->loc('title', $locale), 'url' => $this->path('sv_blog_post', ['slug' => $post->slug($locale)], $locale)];
            }
        }

        $fixed = [['title' => $this->t('nav.services', $locale), 'url' => $this->path('sv_services', [], $locale)]];
        if ($this->districts->findActiveByCity($city) !== []) {
            $fixed[] = ['title' => $this->t('nav.districts', $locale), 'url' => $this->path('sv_districts', [], $locale)];
        }
        if ($this->calculator->isAvailable($site, $city)) {
            $fixed[] = ['title' => $this->t('nav.calculator', $locale), 'url' => $this->path('sv_calculator', [], $locale)];
        }
        if ($site->aboutFor($locale) !== null || $city->aboutFor($locale) !== null) {
            $fixed[] = ['title' => $this->t('nav.about', $locale), 'url' => $this->path('sv_about', [], $locale)];
        }
        foreach (['sv_reviews' => 'nav.reviews', 'sv_faq' => 'nav.faq', 'sv_contacts' => 'nav.contacts'] as $route => $key) {
            if ($this->fixedPages !== null && !$this->fixedPages->enabled(substr($route, 3))) {
                continue;
            }
            $fixed[] = ['title' => $this->t($key, $locale), 'url' => $this->path($route, [], $locale)];
        }

        return ['catalog' => array_values($catalog), 'pages' => array_values($pages), 'posts' => $posts, 'fixed' => $fixed];
    }

    /** @param array<string, string> $params */
    private function path(string $route, array $params, string $locale): string
    {
        return $this->urls->generate($route, $params + ['_locale' => $locale]);
    }

    private function t(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], 'servicesite', $locale);
    }
}
