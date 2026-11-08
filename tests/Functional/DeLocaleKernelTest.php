<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCategory;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServicePostRepository;
use App\ServiceSite\LocalizedRoutes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Мова de (08.11.2026) на справжніх маршрутах і БД рушія: app.locales [uk, de] дає / для uk і /de/… для de у кожній
 * локалізованій групі, коди розмітки de є в app.locale_codes; при типовому [uk, ru] колекція маршрутів та сама.
 * Репозиторії: запис de знаходиться лише за slug_de (без фолбеку на uk), uk — як було.
 */
final class DeLocaleKernelTest extends KernelTestCase
{
    private int $siteId;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $slug = 'ci-de-' . bin2hex(random_bytes(3));
        $site = (new ServiceSite())->setSlug($slug)->setName('CI Deutsch')->setApexDomain($slug . '.example')->setLocales(['uk', 'de']);
        $em->persist($site);
        $past = new \DateTimeImmutable('-1 day');
        $em->persist((new ServicePost())->setSite($site)->setSlugUk('persha')->setTitleUk('Перша')->setSlugDe('erste')->setTitleDe('Erste')->setPublishedAt($past));
        // uk-стаття без німецької версії: за uk-слагом на /de/ не знаходиться
        $em->persist((new ServicePost())->setSite($site)->setSlugUk('druha')->setTitleUk('Друга')->setPublishedAt($past));
        $em->persist((new ServiceCategory())->setSite($site)->setSlugUk('prybyrannia')->setTitleUk('Прибирання')->setSlugDe('reinigung')->setTitleDe('Reinigung')->setActive(true));
        $em->persist((new ServiceCategory())->setSite($site)->setSlugUk('remont')->setTitleUk('Ремонт')->setActive(true));
        $em->flush();
        $this->siteId = (int) $site->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $site = $em->find(ServiceSite::class, $this->siteId);
        if ($site !== null) {
            $em->remove($site); // статті й категорії — ON DELETE CASCADE
            $em->flush();
        }
        parent::tearDown();
    }

    public function testEngineRoutesInUkDeDeployment(): void
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        $routes = $router->getRouteCollection();
        self::assertSame($routes, LocalizedRoutes::apply($routes, ['uk', 'ru']), '[uk, ru] — колекція не змінюється');

        $groups = [];
        foreach ($routes->all() as $name => $route) {
            if (\is_string($route->getDefault('_canonical_route')) && str_ends_with($name, '.uk')) {
                $groups[] = $route->getDefault('_canonical_route');
            }
        }
        self::assertGreaterThanOrEqual(15, \count($groups));

        $de = LocalizedRoutes::apply($routes, ['uk', 'de']);
        $context = new RequestContext('', 'GET', 'example.com', 'https');
        $context->setParameter('_locale', 'uk');
        $gen = new UrlGenerator($de, $context);
        foreach ($groups as $g) {
            $uk = $routes->get($g . '.uk') ?? self::fail("$g.uk є");
            self::assertSame($uk->getPath(), $de->get($g . '.uk')?->getPath(), "$g.uk: шлях без префікса той самий");
            $route = $de->get($g . '.de') ?? self::fail("$g.de є");
            self::assertSame('/de' . $uk->getPath(), $route->getPath(), "$g.de: шлях з префіксом /de");
            self::assertSame('de', $route->getDefault('_locale'));
            self::assertNull($de->get($g . '.ru'), "$g.ru прибрано");
            $params = [];
            foreach ($route->compile()->getPathVariables() as $var) {
                $params[$var] = match ($var) { 'page' => '2', '_calc' => 'calculator', default => 'x' };
            }
            self::assertStringStartsWith('/de/', $gen->generate($g, $params + ['_locale' => 'de']), "$g генерується з префіксом /de/");
            self::assertStringStartsNotWith('/de/', $gen->generate($g, $params), "$g uk (мова контексту) генерується без префікса");
        }

        /** @var array<string, array<string, string>> $codes */
        $codes = self::getContainer()->getParameter('app.locale_codes');
        self::assertSame(['hreflang' => 'de', 'og' => 'de_DE', 'schema' => 'de', 'label' => 'DE'], $codes['de']);
        self::assertSame(['hreflang' => 'uk-UA', 'og' => 'uk_UA', 'schema' => 'uk-UA', 'label' => 'UA'], $codes['uk'], 'uk як було');
        self::assertSame(['hreflang' => 'ru-UA', 'og' => 'ru_UA', 'schema' => 'ru-UA', 'label' => 'RU'], $codes['ru'], 'ru як було');
    }

    public function testRepositoriesFindDeOnlyByOwnSlug(): void
    {
        $em = $this->em();
        $site = $em->find(ServiceSite::class, $this->siteId) ?? self::fail('сайт є');
        /** @var ServicePostRepository $posts */
        $posts = $em->getRepository(ServicePost::class);
        /** @var ServiceCategoryRepository $categories */
        $categories = $em->getRepository(ServiceCategory::class);

        self::assertSame('Erste', $posts->findOnePublishedBySlug($site, 'erste', 'de')?->loc('title', 'de'));
        self::assertNull($posts->findOnePublishedBySlug($site, 'persha', 'de'), 'uk-слаг на /de/ не знаходиться');
        self::assertNull($posts->findOnePublishedBySlug($site, 'druha', 'de'), 'стаття без німецької версії на /de/ — 404');
        self::assertSame('Перша', $posts->findOnePublishedBySlug($site, 'persha', 'uk')?->loc('title', 'uk'));
        self::assertSame('Друга', $posts->findOnePublishedBySlug($site, 'druha', 'ru')?->loc('title', 'ru'), 'ru з фолбеком на uk-слаг, як було');
        $uk = array_map(static fn (ServicePost $p): string => $p->slug('uk'), $posts->findPublishedBySite($site, 10, 'uk'));
        sort($uk);
        self::assertSame(['druha', 'persha'], $uk);
        self::assertSame(['erste'], array_map(static fn (ServicePost $p): string => $p->slug('de'), $posts->findPublishedBySite($site, 10, 'de')), 'список de — лише статті з німецькою версією');

        self::assertSame('Reinigung', $categories->findOneActiveBySlug($site, 'reinigung', 'de')?->loc('title', 'de'));
        self::assertNull($categories->findOneActiveBySlug($site, 'prybyrannia', 'de'));
        self::assertNull($categories->findOneActiveBySlug($site, 'remont', 'de'), 'категорія без slug_de адреси de не має');
        self::assertSame('Ремонт', $categories->findOneActiveBySlug($site, 'remont', 'ru')?->loc('title', 'ru'), 'ru: порожній slug_ru = uk-слаг, як було');
        self::assertNull($categories->findOneActiveBySlug($site, 'prybyrannia', 'de')?->loc('lead', 'de'));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
