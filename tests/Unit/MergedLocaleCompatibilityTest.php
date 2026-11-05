<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServicePage;
use App\Entity\ServicePost;
use App\ServiceSite\Applications;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\CalculatorRoutes;
use App\ServiceSite\LocalizedRoutes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;

/** Сумісність мов #9/#10 із сутностями й кореневими маршрутами main. */
final class MergedLocaleCompatibilityTest extends TestCase
{
    public function testPageWithoutEnglishColumnsShowsNoUkrainianTextAndKeepsNestedPath(): void
    {
        $parent = (new ServicePage())->setSlugUk('jobs')->setTitleUk('Вакансії');
        $page = (new ServicePage())->setParent($parent)->setSlugUk('translator')->setTitleUk('Перекладач')
            ->setLeadUk('Опис вакансії')->setMetaTitleUk('SEO українською');

        self::assertSame('jobs/translator', $page->path('en'));
        // рішення #10: англійська сторінка не показує український текст
        self::assertNull($page->loc('title', 'en'));
        self::assertNull($page->loc('lead', 'en'));
        self::assertSame('Опис вакансії', $page->loc('lead', 'ru'), 'ru як і раніше падає на uk');
        self::assertNull($page->locRaw('title', 'en'), 'raw не підставляє іншу мову');
        self::assertNull($page->loc('metaTitle', 'en'), 'SEO лишається мовою шаблону');

        $post = (new ServicePost())->setSlugUk('news')->setTitleUk('Новина')->setLeadUk('Вступ');
        self::assertNull($post->loc('title', 'en'), 'наявна колонка *En не падає на uk');
        self::assertNull($post->loc('lead', 'en'));
        self::assertFalse($post->existsIn('en'));
    }

    public function testRootSlugsReserveDeploymentLocalesAndParsePrefixedLeadSources(): void
    {
        $root = new CatalogUrls('root', 'blog', [], ['uk', 'en']);
        self::assertContains('en', $root->reservedSegments());
        self::assertTrue($root->isReservedCategorySlug('en'));
        self::assertSame('cleaning', $root->itemSlugFromPath('/en/home/cleaning/'));
        self::assertSame('cleaning', $root->itemSlugFromPath('/home/cleaning/'));

        $slug = new CatalogUrls('slug', 'blog', [], ['en', 'ru']);
        self::assertSame('cleaning', $slug->itemSlugFromPath('/ru/cleaning/'));
        self::assertSame('cleaning', $slug->itemSlugFromPath('/cleaning/'));
    }

    public function testNewRoutesKeepPriorityConditionsAndExcludeAllLanguagePrefixes(): void
    {
        $routes = new RouteCollection();
        $condition = "request.attributes.has('service_city') and service('root_slugs').matches('page', request, params)";
        foreach (['uk' => '/{path}/', 'ru' => '/ru/{path}/'] as $locale => $path) {
            $routes->add('sv_page.' . $locale, new Route($path, ['_locale' => $locale, '_canonical_route' => 'sv_page'],
                ['_locale' => $locale, 'path' => '(?!ru/)[a-z0-9-]+(?:/[a-z0-9-]+)?'], [], '', [], ['GET'], $condition), 5);
        }
        $application = new Route('/application/{form}', [], ['form' => '[a-z0-9_-]+'], [], '', [], ['POST'], "request.attributes.has('service_city')");
        $routes->add('sv_application', $application, 10);
        $routes->addAlias('page_alias', 'sv_page.uk');

        $localized = LocalizedRoutes::apply($routes, ['uk', 'en', 'ru']);
        $en = $localized->get('sv_page.en') ?? self::fail('sv_page.en є');
        self::assertSame('/en/{path}/', $en->getPath());
        self::assertSame($condition, $en->getCondition());
        self::assertSame(['GET'], $en->getMethods());
        self::assertSame(5, $localized->getPriority('sv_page.en'));
        $requirement = $localized->get('sv_page.uk')?->getRequirement('path');
        self::assertDoesNotMatchRegularExpression('~^' . $requirement . '$~', 'en/jobs');
        self::assertDoesNotMatchRegularExpression('~^' . $requirement . '$~', 'ru/jobs');
        self::assertMatchesRegularExpression('~^' . $requirement . '$~', 'jobs/translator');
        self::assertSame($application, $localized->get('sv_application'));
        self::assertSame(10, $localized->getPriority('sv_application'));

        $english = LocalizedRoutes::apply($routes, ['en']);
        self::assertSame('/{path}/', $english->get('sv_page.en')?->getPath());
        self::assertSame('en', $english->get('sv_page.en')?->getDefault('_locale'));
        self::assertSame($english->get('sv_page.en'), $english->get('page_alias'), 'аліас не посилається на видалений .uk');
    }

    public function testApplicationLabelsAndValidationUseEnglishWhileAdminDataStaysUkrainian(): void
    {
        $forms = new Applications(['translator' => [
            'title' => ['uk' => 'Анкета', 'en' => 'Application'],
            'required' => ['name', 'work'],
            'fields' => ['work' => ['label' => ['uk' => 'Робота', 'en' => 'Work'], 'type' => 'list',
                'options' => ['written' => ['uk' => 'Письмовий переклад', 'en' => 'Written translation']]]],
        ]]);
        $view = $forms->view('translator', 'en');
        self::assertSame('Application', $view['title']);
        self::assertSame('Work', $view['fields'][0]['label']);
        self::assertSame('Written translation', $view['fields'][0]['options'][0]['label']);
        self::assertSame('Please fill in “Name”.', $forms->read('translator', new Request(), 'en')['error']);

        $input = $forms->read('translator', Request::create('/application/translator', 'POST', ['name' => 'Alice', 'work' => ['written']]), 'en');
        self::assertNull($input['error']);
        self::assertSame([['key' => 'work', 'label' => 'Робота', 'value' => 'Письмовий переклад']], $input['data']);
    }

    public function testCalculatorKeepsModePathsAndNonlocalizedPostEndpoint(): void
    {
        $routes = LocalizedRoutes::apply(CalculatorRoutes::collection([]), ['en', 'uk']);
        $context = new RequestContext();
        $context->setParameter('_locale', 'en');
        $urls = new UrlGenerator($routes, $context);
        self::assertSame('/calculator-cleaning/', $urls->generate('sv_calculator'));
        self::assertSame('/uk/calculator/', $urls->generate('sv_calculator', ['_locale' => 'uk', '_calc' => 'calculator']));
        self::assertSame('/calculator-cleaning/lead', $urls->generate('sv_calc_lead'));
        self::assertSame(['POST'], $routes->get('sv_calc_lead')?->getMethods());
        self::assertSame(10, $routes->getPriority('sv_calculator.en'));
        self::assertSame(CalculatorRoutes::CONDITION, $routes->get('sv_calculator.en')?->getCondition());
    }
}
