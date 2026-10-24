<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use Symfony\Bundle\FrameworkBundle\Routing\Attribute\AsRoutingConditionService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Маршрути, що залежать від бренду (24.10.2026, кілька брендів в одному розгортанні):
 *  - розділ статей: ServiceSite.blogPrefix ?? app.blog_prefix. Маршрути блогу оголошені як /{_blog}/… з умовою
 *    params['_blog'] == service('brand_routes').blog(request): на хості бренду матчиться лише його розділ, а path()
 *    бере _blog з параметра контексту роутера (ставить SiteLocaleSubscriber), тож шаблони не змінюються;
 *  - мова без префіксів (app.locale_prefix: false): service('brand_routes').locale(request) — перша мова бренду.
 *
 * Бренд — з атрибута самого запиту (не головного): SiteLiveUrls матчить власний запит з атрибутами тенанта.
 */
#[AsRoutingConditionService(alias: 'brand_routes')]
final class BrandRoutes
{
    public function __construct(
        private readonly SiteLocales $locales,
        #[Autowire('%app.blog_prefix%')]
        private readonly string $blogPrefix = 'blog',
    ) {
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
