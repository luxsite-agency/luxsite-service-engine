<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouteCollection;

/**
 * Завантажувач маршрутів калькулятора (тег routing.loader, тип «calculator»): Kernel::configureRoutes робить
 * $routes->import('.', 'calculator'). Окремий лоадер, бо PHP-DSL роутингу не задає пріоритет, а шлях з паку —
 * мапа {uk, ru}, яку не підставити як %параметр% у YAML.
 */
final class CalculatorRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(
        #[Autowire('%app.calculator.path%')]
        private readonly mixed $path = [],
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \RuntimeException('Маршрути калькулятора вже завантажено.');
        }
        $this->loaded = true;

        return CalculatorRoutes::collection($this->path);
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'calculator';
    }
}
