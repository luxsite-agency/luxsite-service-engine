<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServicePostCategory;
use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use App\Repository\ServicePostCategoryRepository;
use Symfony\Bundle\FrameworkBundle\Routing\Attribute\AsRoutingConditionService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Маршрути, що залежать від бренду (24.10.2026, кілька брендів в одному розгортанні):
 *  - розділ статей: ServiceSite.blogPrefix ?? app.blog_prefix. Маршрути блогу оголошені як /{_blog}/… з умовою
 *    params['_blog'] == service('brand_routes').blog(request): на хості бренду матчиться лише його розділ, а path()
 *    бере _blog з параметра контексту роутера (ставить SiteLocaleSubscriber), тож шаблони не змінюються;
 *  - мова без префіксів (app.locale_prefix: false): service('brand_routes').locale(request) — перша мова бренду;
 *  - рубрики від кореня (app.brands.<slug>.category_url: root, 24.10.2026): маршрут /{slug}/ з умовою
 *    service('brand_routes').rootCategory(request, params) — у бренду є активна рубрика з таким slug і він не зайнятий
 *    фіксованим розділом; path('sv_blog_category', …) дає /<slug>/ (App\Routing\BrandRouter за параметром контексту _cat_root).
 *
 * Бренд — з атрибута самого запиту (не головного): SiteLiveUrls матчить власний запит з атрибутами тенанта.
 */
#[AsRoutingConditionService(alias: 'brand_routes')]
final class BrandRoutes implements ResetInterface
{
    /** @var array<string, ServicePostCategory|null> memo на запит: умова маршруту й контролер шукають ту саму рубрику */
    private array $memo = [];

    public function __construct(
        private readonly SiteLocales $locales,
        private readonly BrandSettings $brands,
        private readonly ServicePostCategoryRepository $categories,
        #[Autowire('%app.blog_prefix%')]
        private readonly string $blogPrefix = 'blog',
    ) {
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * Параметри контексту роутера для адрес бренду: розділ статей (_blog) і рубрики від кореня (_cat_root).
     * Ставлять SiteLocaleSubscriber (хост бренду) і студія (адмін-хост).
     *
     * @return array{_blog: string, _cat_root: bool}
     */
    public function contextParams(?ServiceSite $site): array
    {
        return ['_blog' => $this->blogOf($site), '_cat_root' => $site !== null && $this->brands->categoryRoot($site)];
    }

    /**
     * Умова маршруту рубрики від кореня: бренд з category_url: root, slug не зайнятий фіксованим розділом бренду й є
     * активна рубрика з таким slug мовою збігу.
     *
     * @param array<string, mixed> $params
     */
    public function rootCategory(?Request $request, array $params): bool
    {
        $site = self::siteOf($request);
        if ($site === null || !$this->brands->categoryRoot($site)) {
            return false;
        }
        $slug = (string) ($params['slug'] ?? '');

        return !\in_array($slug, $this->reservedFor($site), true)
            && $this->category($site, $slug, (string) ($params['_locale'] ?? $this->locales->defaultFor($site))) !== null;
    }

    /**
     * Перші сегменти, яких не може мати рубрика від кореня: фіксовані розділи сайту й розділ статей бренду. blog і news
     * зайняті лише як розділ статей — у бренду з іншим розділом це звичайні slug (рубрика tvi «news»).
     *
     * @return list<string>
     */
    public function reservedFor(ServiceSite $site): array
    {
        return array_values(array_unique([...array_diff(CatalogUrls::RESERVED_SEGMENTS, ['blog', 'news']), $this->blogOf($site)]));
    }

    public function category(ServiceSite $site, string $slug, string $locale): ?ServicePostCategory
    {
        $key = $site->getId() . ':' . $locale . ':' . $slug;
        if (!\array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $slug === '' ? null : $this->categories->findOneActiveBySlug($site, $slug, $locale);
        }

        return $this->memo[$key];
    }

    public function blog(?Request $request): string
    {
        return $this->blogOf(self::siteOf($request));
    }

    public function blogOf(?ServiceSite $site): string
    {
        return $site?->getBlogPrefix() ?? $this->blogPrefix;
    }

    public function locale(?Request $request): string
    {
        return $this->locales->defaultFor(self::siteOf($request));
    }

    private static function siteOf(?Request $request): ?ServiceSite
    {
        $site = $request?->attributes->get(ServiceHostSubscriber::ATTR_SITE);

        return $site instanceof ServiceSite ? $site : null;
    }
}
