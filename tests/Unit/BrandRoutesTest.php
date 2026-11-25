<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use App\Repository\ServicePostCategoryRepository;
use App\Routing\BrandRouter;
use App\ServiceSite\BrandRoutes;
use App\ServiceSite\BrandSettings;
use App\ServiceSite\LocalizedRoutes;
use App\ServiceSite\SiteLocales;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;

/**
 * Бренди різними мовами й розділами статей в одному розгортанні (24.10.2026): app.locales [en, uk] без префіксів —
 * англійський бренд /blog/…, український /novyny/…; адреса однакова для будь-якої мови генерації, мова — від бренду.
 */
final class BrandRoutesTest extends TestCase
{
    private const BLOG = "request.attributes.has('service_city') and params['_blog'] == service('brand_routes').blog(request)";

    private function routes(): RouteCollection
    {
        $c = new RouteCollection();
        foreach (['sv_home' => ['/', [], [], ''], 'sv_blog_post' => ['/{_blog}/{slug}/', ['_blog' => 'blog'], ['_blog' => '[a-z0-9-]+'], self::BLOG]] as $name => [$path, $defaults, $req, $cond]) {
            foreach (['uk' => $path, 'ru' => '/ru' . $path] as $locale => $p) {
                $c->add($name . '.' . $locale, new Route($p, ['_locale' => $locale, '_canonical_route' => $name] + $defaults, ['_locale' => $locale] + $req, [], '', [], [], $cond), 10);
            }
        }

        return $c;
    }

    private function brand(string $slug, array $locales, ?string $blog): ServiceSite
    {
        return (new ServiceSite())->setSlug($slug)->setName($slug)->setApexDomain($slug . '.example')->setMode(ServiceSite::MODE_CONTENT)
            ->setLocales($locales)->setBlogPrefix($blog);
    }

    /** @return array<string, mixed> */
    private function match(RouteCollection $routes, BrandRoutes $brandRoutes, ServiceSite $site, string $path): array
    {
        $request = Request::create('https://' . $site->getApexDomain() . $path);
        $request->attributes->set(ServiceHostSubscriber::ATTR_SITE, $site);
        $request->attributes->set(ServiceHostSubscriber::ATTR_CITY, true);
        $matcher = new UrlMatcher($routes, (new RequestContext())->fromRequest($request));
        $matcher->addExpressionLanguageProvider(new class($brandRoutes) implements ExpressionFunctionProviderInterface {
            public function __construct(private readonly BrandRoutes $brandRoutes)
            {
            }

            public function getFunctions(): array
            {
                return [new ExpressionFunction('service', static fn (): string => '', fn (array $vars, string $id): BrandRoutes => $this->brandRoutes)];
            }
        });

        return $matcher->matchRequest($request);
    }

    public function testPrefixlessLocalesAndBlogPerBrand(): void
    {
        $routes = LocalizedRoutes::apply($this->routes(), ['en', 'uk'], false);
        self::assertSame(['sv_home.en', 'sv_home.uk', 'sv_blog_post.en', 'sv_blog_post.uk'], array_values(array_filter(array_keys($routes->all()), static fn (string $n): bool => !str_ends_with($n, LocalizedRoutes::SUB))));
        self::assertSame('/', $routes->get('sv_home.uk')?->getPath(), 'друга мова теж без префікса');
        self::assertSame(10, $routes->getPriority('sv_blog_post.uk'));

        $brandRoutes = new BrandRoutes(new SiteLocales(new RequestStack(), ['en', 'uk'], [], 'uk', false), new BrandSettings(), $this->createStub(ServicePostCategoryRepository::class), 'blog');
        $en = $this->brand('trysail', ['en'], null);
        $uk = $this->brand('eplus', ['uk'], 'novyny');

        $m = $this->match($routes, $brandRoutes, $uk, '/novyny/x/');
        self::assertSame(['sv_blog_post', 'uk', 'x', 'novyny'], [$m['_route'], $m['_locale'], $m['slug'], $m['_blog']]);
        self::assertSame(['sv_home', 'uk'], [($h = $this->match($routes, $brandRoutes, $uk, '/'))['_route'], $h['_locale']], 'головна українського бренду — uk');
        $m = $this->match($routes, $brandRoutes, $en, '/blog/x/');
        self::assertSame(['sv_blog_post', 'en'], [$m['_route'], $m['_locale']]);
        self::assertSame('en', $this->match($routes, $brandRoutes, $en, '/')['_locale']);

        foreach ([[$uk, '/blog/x/'], [$en, '/novyny/x/'], [$uk, '/uk/novyny/x/']] as [$site, $path]) {
            try {
                $this->match($routes, $brandRoutes, $site, $path);
                self::fail($site->getSlug() . $path . ' не має матчитись');
            } catch (ResourceNotFoundException) {
                self::addToAssertionCount(1);
            }
        }

        // генерація: будь-якою мовою — та сама адреса; розділ — з параметра контексту _blog
        $context = new RequestContext('', 'GET', 'eplus.example', 'https');
        $context->setParameter('_blog', 'novyny');
        $gen = new UrlGenerator($routes, $context);
        self::assertSame('/novyny/x/', $gen->generate('sv_blog_post', ['slug' => 'x', '_locale' => 'uk']));
        self::assertSame('/novyny/x/', $gen->generate('sv_blog_post', ['slug' => 'x', '_locale' => 'en']));
        self::assertSame('/blog/x/', (new UrlGenerator($routes, new RequestContext()))->generate('sv_blog_post', ['slug' => 'x', '_locale' => 'en']), 'без контексту бренду — app.blog_prefix');
    }

    public function testSecondLocalePerBrand(): void
    {
        $source = $this->routes();
        // маршрут-«ловець»: не має перехоплювати /ru/… бренду з другою мовою
        foreach (['uk' => '/{path}/', 'ru' => '/ru/{path}/'] as $locale => $p) {
            $source->add('sv_page.' . $locale, new Route($p, ['_locale' => $locale, '_canonical_route' => 'sv_page'], ['_locale' => $locale, 'path' => '.+']), -10);
        }
        $source->add('sv_lead', new Route('/lead'));
        $routes = LocalizedRoutes::apply($source, ['en', 'uk', 'ru'], false);

        $locales = new SiteLocales(new RequestStack(), ['en', 'uk', 'ru'], [], 'uk', false);
        $brandRoutes = new BrandRoutes($locales, new BrandSettings(), $this->createStub(ServicePostCategoryRepository::class), 'blog');
        $two = $this->brand('uaweb', ['uk', 'ru'], null);
        $one = $this->brand('eplus', ['uk'], null);
        $ruFirst = $this->brand('yx', ['ru', 'uk'], null);

        foreach ([['/', 'sv_home', 'uk'], ['/ru/', 'sv_home', 'ru'], ['/blog/x/', 'sv_blog_post', 'uk'], ['/ru/blog/x/', 'sv_blog_post', 'ru'], ['/ru/abc/', 'sv_page', 'ru']] as [$path, $route, $locale]) {
            $m = $this->match($routes, $brandRoutes, $two, $path);
            self::assertSame([$route, $locale], [$m['_route'], $m['_locale']], $path);
        }
        // сусід без другої мови: /ru/… — як було, звичайний шлях безпрефіксного «ловця»
        $m = $this->match($routes, $brandRoutes, $one, '/ru/abc/');
        self::assertSame(['sv_page', 'uk', 'ru/abc'], [$m['_route'], $m['_locale'], $m['path']]);
        self::assertSame(['ru', 'uk'], [$this->match($routes, $brandRoutes, $ruFirst, '/')['_locale'], $this->match($routes, $brandRoutes, $ruFirst, '/uk/')['_locale']]);
        self::assertSame(['uk', 'ru'], $locales->enabled($two));
        self::assertSame(['ru', 'uk'], $locales->enabled($ruFirst), 'порядок бренду, не розгортання');
        self::assertSame([], $locales->secondaryFor($one));
        self::assertSame([], $locales->secondaryFor(new ServiceSite()), 'бренд без набору — без префіксів, як було');
        self::assertSame([], (new SiteLocales(new RequestStack(), ['uk', 'ru']))->secondaryFor($two), 'з префіксами механізм не діє');

        $context = new RequestContext('', 'GET', 'uaweb.example', 'https');
        $context->setParameter('_locale', 'uk');
        foreach ($brandRoutes->contextParams($two) as $name => $value) {
            $context->setParameter($name, $value);
        }
        $router = new BrandRouter(new class(new ClosureLoader(), static fn (): RouteCollection => $routes, [], $context) extends Router implements WarmableInterface {
            public function warmUp(string $cacheDir, ?string $buildDir = null): array
            {
                return [];
            }
        });
        self::assertSame('/blog/x/', $router->generate('sv_blog_post', ['slug' => 'x']));
        self::assertSame('/ru/blog/x/', $router->generate('sv_blog_post', ['slug' => 'x', '_locale' => 'ru']));
        self::assertSame('/ru/', $router->generate('sv_home', ['_locale' => 'ru']));
        self::assertSame('/lead', $router->generate('sv_lead'), 'нелокалізований маршрут — без префікса');
        $context->setParameter('_locale', 'ru');
        self::assertSame('/ru/blog/x/', $router->generate('sv_blog_post', ['slug' => 'x']), 'на сторінці другою мовою посилання лишаються під префіксом');
        self::assertSame('/blog/x/', $router->generate('sv_blog_post', ['slug' => 'x', '_locale' => 'uk']), 'перемикач на мову сайту');
        // бренд без другої мови: будь-якою мовою — та сама безпрефіксна адреса
        $context->setParameter('_sub', $brandRoutes->subOf($one));
        self::assertSame('/blog/x/', $router->generate('sv_blog_post', ['slug' => 'x', '_locale' => 'ru']));
        // явний параметр (авто-301 з адмін-хоста) і не потрапляє в query
        self::assertSame('/ru/blog/x/', $router->generate('sv_blog_post', ['slug' => 'x', '_locale' => 'ru', '_sub' => 'ru']));
    }

    public function testAdminFieldsKeepLocaleOrder(): void
    {
        $site = $this->brand('uaweb', ['uk'], null)->setExtraLocales(['en', 'ru']);
        self::assertSame(['uk', 'en', 'ru'], $site->getLocales());
        self::assertSame(['en', 'ru'], $site->getExtraLocales());
        self::assertSame(['ru', 'en'], $site->setPrimaryLocale('ru')->getLocales(), 'головна = колишня додаткова — дубль прибрано');
        self::assertSame(['ru', 'uk'], $site->setExtraLocales(['uk', 'ru'])->getLocales(), 'головна серед додаткових ігнорується');
        self::assertSame(['ru'], $site->setExtraLocales([])->getLocales());

        $locales = new SiteLocales(new RequestStack(), ['en', 'uk', 'ru'], [], 'uk', false);
        self::assertSame(['en', 'ru'], $locales->secondaryFor($this->brand('x', ['uk', 'en', 'ru'], null)), 'кілька додаткових мов');
    }

    public function testSingleDeploymentLocaleNeedsNoCondition(): void
    {
        $routes = LocalizedRoutes::apply($this->routes(), ['en'], false);
        self::assertSame('', $routes->get('sv_home.en')?->getCondition());
        self::assertSame(self::BLOG, $routes->get('sv_blog_post.en')?->getCondition());
    }

    public function testBrandDefaultLocale(): void
    {
        $uk = $this->brand('eplus', ['uk'], null);
        $prefixless = new SiteLocales(new RequestStack(), ['en', 'uk'], [], 'uk', false);
        self::assertSame('uk', $prefixless->defaultFor($uk));
        self::assertSame('en', $prefixless->defaultFor(null), 'хост без бренду — перша мова розгортання');
        self::assertSame('en', $prefixless->ofPath('/uk/x/'), 'без префіксів /uk/ — не мова, а звичайний сегмент');
        self::assertSame('en', (new SiteLocales(new RequestStack(), ['en', 'uk']))->defaultFor($uk), 'з префіксами — перша мова розгортання');
    }
}
