<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\CalculatorRoutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;

/**
 * Маршрути калькулятора з паку (app.calculator.path): статичні адреси, обидві типові → 301 з переносом query;
 * без параметра — один маршрут з {_calc} на оба типові сегменти, а сегмент підставляє RequestContext.
 */
final class CalculatorRoutesTest extends TestCase
{
    public function testDefaultRoutesAcceptBothSegmentsAndFollowContext(): void
    {
        $routes = CalculatorRoutes::collection([]);
        self::assertSame(['sv_calculator.uk', 'sv_calculator.ru', 'sv_calc_lead'], array_keys($routes->all()));
        self::assertSame('calculator-cleaning|calculator', $routes->get('sv_calculator.uk')->getRequirement('_calc'));
        self::assertSame('calculator-cleaning', $routes->get('sv_calc_lead')->getDefault('_calc'));
        self::assertSame(10, $routes->getPriority('sv_calculator.uk'));

        $generator = new UrlGenerator($routes, new RequestContext());
        self::assertSame('/calculator-cleaning/', $generator->generate('sv_calculator', ['_locale' => 'uk']), 'без контексту — типова адреса режиму за площею');
        $generator->getContext()->setParameter('_calc', 'calculator');
        self::assertSame('/calculator/', $generator->generate('sv_calculator', ['_locale' => 'uk']));
        self::assertSame('/ru/calculator/', $generator->generate('sv_calculator', ['_locale' => 'ru']));
        self::assertSame('/calculator/lead', $generator->generate('sv_calc_lead'));
        self::assertStringNotContainsString('_calc', $generator->generate('sv_calculator', ['_locale' => 'uk']), 'параметр контексту не йде в query');
    }

    public function testPackPathIsStaticAndLegacyPathsRedirect(): void
    {
        $routes = CalculatorRoutes::collection(['uk' => '/kalkuliator/', 'ru' => '/ru/kalkuliator/']);
        self::assertSame('/kalkuliator/', $routes->get('sv_calculator.uk')->getPath());
        self::assertSame('/ru/kalkuliator/', $routes->get('sv_calculator.ru')->getPath());
        self::assertSame('/kalkuliator/lead', $routes->get('sv_calc_lead')->getPath());
        self::assertSame(['POST'], $routes->get('sv_calc_lead')->getMethods());

        foreach (['calculator_cleaning' => '/calculator-cleaning/', 'calculator' => '/calculator/'] as $key => $path) {
            $legacy = $routes->get('sv_calculator_legacy_' . $key . '.uk');
            self::assertNotNull($legacy, $path);
            self::assertSame($path, $legacy->getPath());
            self::assertSame(RedirectController::class . '::redirectAction', $legacy->getDefault('_controller'));
            self::assertSame('sv_calculator', $legacy->getDefault('route'));
            self::assertTrue($legacy->getDefault('permanent'));
            self::assertTrue($legacy->getDefault('keepQueryParams'));
            self::assertSame('/ru' . $path, $routes->get('sv_calculator_legacy_' . $key . '.ru')->getPath());
        }
        self::assertSame("request.attributes.has('service_city')", $routes->get('sv_calculator_legacy_calculator.uk')->getCondition());

        // пак лишив типову адресу площі — 301 лише з другої типової
        $routes = CalculatorRoutes::collection(['uk' => '/calculator-cleaning/', 'ru' => '/ru/calculator-cleaning/']);
        self::assertNull($routes->get('sv_calculator_legacy_calculator_cleaning.uk'));
        self::assertNotNull($routes->get('sv_calculator_legacy_calculator.uk'));
        self::assertSame('calculator-cleaning', CalculatorRoutes::firstSegment(['uk' => '/calculator-cleaning/', 'ru' => '/ru/calculator-cleaning/']));
        self::assertNull(CalculatorRoutes::firstSegment([]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function badPaths(): iterable
    {
        yield 'рядок' => ['/calc/'];
        yield 'без ru' => [['uk' => '/calc/']];
        yield 'без слеша' => [['uk' => '/calc', 'ru' => '/ru/calc/']];
        yield 'ru без префікса' => [['uk' => '/calc/', 'ru' => '/calc/']];
        yield 'великі літери' => [['uk' => '/Calc/', 'ru' => '/ru/Calc/']];
    }

    #[DataProvider('badPaths')]
    public function testInvalidPathIsRejected(mixed $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CalculatorRoutes::normalizePath($path);
    }
}
