<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Routing;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Рубрики від кореня (24.10.2026, app.brands.<slug>.category_url: root): на хості такого бренду (параметр контексту
 * роутера _cat_root, ставлять SiteLocaleSubscriber і студія) адреса рубрики — /<slug>/ замість /<блог>/category/<slug>/.
 * Генерація sv_blog_category(_page) підміняється на sv_blog_category_root(_page) тут, щоб шаблони рушія й паків, sitemap,
 * canonical і хлібні крихти не змінювались. Решта — без змін, матчинг — як був.
 */
#[AsDecorator('router')]
final class BrandRouter implements RouterInterface, RequestMatcherInterface, WarmableInterface
{
    /** Маршрут рубрики → його варіант від кореня */
    public const ROOT_ROUTES = ['sv_blog_category' => 'sv_blog_category_root', 'sv_blog_category_page' => 'sv_blog_category_root_page', 'sv_blog_category_feed' => 'sv_blog_category_root_feed'];

    public function __construct(
        #[AutowireDecorated]
        private readonly RouterInterface&RequestMatcherInterface&WarmableInterface $inner,
    ) {
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        if (isset(self::ROOT_ROUTES[$name]) && $this->inner->getContext()->getParameter('_cat_root') === true) {
            $name = self::ROOT_ROUTES[$name];
        }

        return $this->inner->generate($name, $parameters, $referenceType);
    }

    public function setContext(RequestContext $context): void
    {
        $this->inner->setContext($context);
    }

    public function getContext(): RequestContext
    {
        return $this->inner->getContext();
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->inner->getRouteCollection();
    }

    public function match(string $pathinfo): array
    {
        return $this->inner->match($pathinfo);
    }

    public function matchRequest(Request $request): array
    {
        return $this->inner->matchRequest($request);
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner->warmUp($cacheDir, $buildDir);
    }
}
