<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Controller\Services\CalculatorController;
use Symfony\Bundle\FrameworkBundle\Controller\RedirectController;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Маршрути калькулятора (sv_calculator, sv_calc_lead) залежать від режиму й паку: Kernel::configureRoutes імпортує їх через
 * CalculatorRouteLoader (тип «calculator»), колекцію збирає collection().
 *
 * Без app.calculator.path адреса типова за режимом: «за площею» (є типи прибирання) — /calculator-cleaning/,
 * «за прайсом» — /calculator/. Режим відомий лише під запитом (з БД сайту), а маршрути збираються при компіляції, тож
 * один маршрут приймає оба сегменти через плейсхолдер {_calc}, а потрібний підставляє CalculatorRouteSubscriber
 * у RequestContext (UrlGenerator бере параметри контексту замість дефолтів маршруту) — path('sv_calculator') у шаблонах
 * і паках працює без змін. Нетипова для режиму адреса → 301 у контролері.
 *
 * Заданий паком шлях — статичний; обидві типові адреси (окрім тої, що збігається) → 301 на нього з переносом query.
 */
final class CalculatorRoutes
{
    public const PLACEHOLDER = '_calc';
    public const SEGMENT_AREA = 'calculator-cleaning';
    public const SEGMENT_PRICELIST = 'calculator';
    public const CONDITION = "request.attributes.has('service_city')";

    /** @return array{uk: string, ru: string} типова адреса режиму */
    public static function defaultPath(string $mode): array
    {
        $seg = $mode === CalculatorAvailability::MODE_PRICELIST ? self::SEGMENT_PRICELIST : self::SEGMENT_AREA;

        return ['uk' => '/' . $seg . '/', 'ru' => '/ru/' . $seg . '/'];
    }

    /**
     * Перевірка app.calculator.path (дзеркало — bin/site.d/_validate.py).
     *
     * @return array{uk: string, ru: string}|null null = порожньо (типові адреси)
     */
    public static function normalizePath(mixed $path): ?array
    {
        if ($path === null || $path === [] || $path === '') {
            return null;
        }
        if (!\is_array($path) || !isset($path['uk'], $path['ru']) || !\is_string($path['uk']) || !\is_string($path['ru'])) {
            throw new \InvalidArgumentException("app.calculator.path: очікується мапа {uk: '/…/', ru: '/ru/…/'} або порожньо.");
        }
        foreach (['uk' => '', 'ru' => '/ru'] as $locale => $prefix) {
            if (!preg_match('~^' . $prefix . '(/[a-z0-9-]+)+/$~', $path[$locale])) {
                throw new \InvalidArgumentException(sprintf(
                    'app.calculator.path.%s: очікується шлях із сегментів [a-z0-9-] зі слешем на кінці%s, отримано «%s».',
                    $locale,
                    $locale === 'ru' ? ' і префіксом /ru/' : '',
                    $path[$locale],
                ));
            }
        }

        return ['uk' => $path['uk'], 'ru' => $path['ru']];
    }

    /** Перший сегмент заданого паком шляху (для резерву серед слагів категорій) або null. */
    public static function firstSegment(mixed $path): ?string
    {
        $normalized = self::normalizePath($path);

        return $normalized === null ? null : explode('/', trim($normalized['uk'], '/'))[0];
    }

    /** Маршрути калькулятора з пріоритетом 10 (як атрибути контролерів): перед каталогом схеми root (/{category}/). */
    public static function collection(mixed $path): RouteCollection
    {
        $routes = new RouteCollection();
        $custom = self::normalizePath($path);
        $index = [CalculatorController::class, 'index'];
        $submit = [CalculatorController::class, 'submit'];

        if ($custom === null) {
            $segments = self::SEGMENT_AREA . '|' . self::SEGMENT_PRICELIST;
            self::addLocalized($routes, 'sv_calculator', ['uk' => '/{_calc}/', 'ru' => '/ru/{_calc}/'], $index, [self::PLACEHOLDER => self::SEGMENT_AREA], [self::PLACEHOLDER => $segments]);
            $routes->add('sv_calc_lead', self::route('/{_calc}/lead', $submit, [self::PLACEHOLDER => self::SEGMENT_AREA], [self::PLACEHOLDER => $segments], ['POST']), 10);

            return $routes;
        }

        self::addLocalized($routes, 'sv_calculator', $custom, $index);
        $routes->add('sv_calc_lead', self::route($custom['uk'] . 'lead', $submit, [], [], ['POST']), 10);

        // типові адреси обох режимів → 301 на адресу паку (стара адреса могла бути в індексі й у посиланнях)
        foreach ([self::SEGMENT_AREA, self::SEGMENT_PRICELIST] as $seg) {
            $legacy = ['uk' => '/' . $seg . '/', 'ru' => '/ru/' . $seg . '/'];
            if ($legacy['uk'] === $custom['uk'] && $legacy['ru'] === $custom['ru']) {
                continue;
            }
            self::addLocalized(
                $routes,
                'sv_calculator_legacy_' . str_replace('-', '_', $seg),
                $legacy,
                [RedirectController::class, 'redirectAction'],
                // _canonical_route старого маршруту не має потрапити в query нової адреси
                ['route' => 'sv_calculator', 'permanent' => true, 'keepQueryParams' => true, 'ignoreAttributes' => ['_canonical_route']],
            );
        }

        return $routes;
    }

    /**
     * Локалізований маршрут як у #[Route(path: ['uk' => …, 'ru' => …])]: <name>.uk / <name>.ru з _locale і _canonical_route.
     *
     * @param array{uk: string, ru: string} $paths
     * @param array{0: class-string, 1: string} $controller
     * @param array<string, mixed>  $defaults
     * @param array<string, string> $requirements
     */
    private static function addLocalized(RouteCollection $routes, string $name, array $paths, array $controller, array $defaults = [], array $requirements = []): void
    {
        foreach ($paths as $locale => $path) {
            $route = self::route($path, $controller, $defaults + ['_locale' => $locale, '_canonical_route' => $name], $requirements + ['_locale' => preg_quote($locale)]);
            $routes->add($name . '.' . $locale, $route, 10);
        }
    }

    /**
     * @param array{0: class-string, 1: string} $controller
     * @param array<string, mixed>  $defaults
     * @param array<string, string> $requirements
     * @param list<string> $methods
     */
    private static function route(string $path, array $controller, array $defaults, array $requirements, array $methods = []): Route
    {
        return new Route($path, ['_controller' => $controller[0] . '::' . $controller[1]] + $defaults, $requirements, [], '', [], $methods, self::CONDITION);
    }
}
