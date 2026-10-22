<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\LocalizedRoutes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class LocalizedRoutesTest extends TestCase
{
    /** Колекція як у рушії: локалізовані пари uk + /ru/ з пріоритетами, нелокалізований маршрут, аліас. */
    private function engineLike(): RouteCollection
    {
        $c = new RouteCollection();
        $c->add('admin', new Route('/admin'));
        foreach (['sv_home' => ['/', 10], 'sv_blog_page' => ['/blog/page/{page}/', 11], 'sv_blog_post' => ['/blog/{slug}/', 10]] as $name => [$path, $prio]) {
            foreach (['uk' => $path, 'ru' => '/ru' . $path] as $locale => $p) {
                $c->add($name . '.' . $locale, new Route($p, ['_locale' => $locale, '_canonical_route' => $name], ['_locale' => $locale]), $prio);
            }
        }
        $c->add('sv_robots', new Route('/robots.txt'), 10);
        $c->addAlias('home_alias', 'sv_home.uk');

        return $c;
    }

    public function testDeclaredLocalesKeepTheSameCollection(): void
    {
        $c = $this->engineLike();
        self::assertSame($c, LocalizedRoutes::apply($c, ['uk', 'ru']), 'типове [uk, ru] — колекція не перебудовується');
    }

    public function testEnglishOnlyRenamesUnprefixedVariantAndDropsRu(): void
    {
        $c = $this->engineLike();
        $before = array_keys($c->all());
        $out = LocalizedRoutes::apply($c, ['en']);

        self::assertSame(
            array_map(static fn (string $n): string => str_ends_with($n, '.uk') ? substr($n, 0, -3) . '.en' : $n, array_values(array_filter($before, static fn (string $n): bool => !str_ends_with($n, '.ru')))),
            array_keys($out->all()),
            'порядок зіставлення той самий, варіанти .ru прибрано, .uk → .en',
        );
        self::assertSame(11, $out->getPriority('sv_blog_page.en'), 'пріоритет збережено');
        self::assertSame(10, $out->getPriority('sv_robots'));
        $home = $out->get('sv_home.en');
        self::assertNotNull($home);
        self::assertSame('/', $home->getPath());
        self::assertSame('en', $home->getDefault('_locale'));
        self::assertSame('en', $home->getRequirement('_locale'));
        self::assertNull($out->get('sv_home.uk'));
        self::assertNull($out->get('sv_home.ru'));
        self::assertNotNull($out->getAlias('home_alias'));
    }

    public function testSecondaryLocalesGetPrefixAndGenerateAndMatch(): void
    {
        $out = LocalizedRoutes::apply($this->engineLike(), ['en', 'de']);
        self::assertSame('/de/blog/{slug}/', $out->get('sv_blog_post.de')?->getPath());

        $context = new RequestContext('', 'GET', 'example.com');
        $context->setParameter('_locale', 'en');
        $gen = new UrlGenerator($out, $context);
        self::assertSame('/', $gen->generate('sv_home'), 'мова контексту без префікса');
        self::assertSame('/de/', $gen->generate('sv_home', ['_locale' => 'de']));
        self::assertSame('/blog/x/', $gen->generate('sv_blog_post', ['slug' => 'x']));

        $matched = (new UrlMatcher($out, $context))->match('/de/blog/page/2/');
        self::assertSame('sv_blog_page.de', $matched['_route']);
        self::assertSame('de', $matched['_locale']);
    }

    public function testInvalidLocalesRejected(): void
    {
        foreach ([[], ['EN'], ['en', 'en'], ['eng']] as $bad) {
            try {
                LocalizedRoutes::apply(new RouteCollection(), $bad);
                self::fail('очікувався виняток для ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
