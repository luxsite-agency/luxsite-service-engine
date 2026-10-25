<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Ядро з параметрами паку, яких у тестовому середовищі нема (parameters.local.yaml не вантажиться): адреса калькулятора
 * app.calculator.path. Окремий кеш, щоб не перетиратися з основним тестовим контейнером.
 */
final class CalcPathKernel extends Kernel
{
    /** @var array{uk: string, ru: string} */
    public static array $calculatorPath = ['uk' => '/kalkuliator/', 'ru' => '/ru/kalkuliator/'];

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.calculator.path', self::$calculatorPath);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/calcpath';
    }
}
