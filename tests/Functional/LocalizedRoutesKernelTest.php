<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ServiceSite\LocalizedRoutes;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Справжні маршрути рушія: при [uk, ru] колекція та сама, при [en] кожна локалізована група має варіант .en без
 * префікса, жодного .uk/.ru, і всі публічні маршрути генеруються.
 */
final class LocalizedRoutesKernelTest extends KernelTestCase
{
    public function testEngineRoutesInEnglishDeployment(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        $routes = $router->getRouteCollection();
        self::assertSame($routes, LocalizedRoutes::apply($routes, ['uk', 'ru']));

        $groups = [];
        foreach ($routes->all() as $name => $route) {
            if (\is_string($route->getDefault('_canonical_route')) && str_ends_with($name, '.uk')) {
                $groups[] = $route->getDefault('_canonical_route');
            }
        }
        self::assertGreaterThanOrEqual(15, \count($groups), 'локалізовані групи рушія знайдено');

        $en = LocalizedRoutes::apply($routes, ['en']);
        $context = new RequestContext('', 'GET', 'example.com', 'https');
        $context->setParameter('_locale', 'en');
        $gen = new UrlGenerator($en, $context);
        foreach ($groups as $g) {
            self::assertNull($en->get($g . '.uk'), "$g.uk прибрано");
            self::assertNull($en->get($g . '.ru'), "$g.ru прибрано");
            $route = $en->get($g . '.en') ?? self::fail("$g.en є");
            self::assertSame($routes->get($g . '.uk')?->getPath(), $route->getPath(), "$g: шлях без префікса той самий");
            $params = [];
            foreach ($route->compile()->getPathVariables() as $var) {
                $params[$var] = $var === 'page' ? '2' : 'x';
            }
            self::assertStringNotContainsString('/en/', $gen->generate($g, $params), "$g генерується без префікса");
        }
    }
}
