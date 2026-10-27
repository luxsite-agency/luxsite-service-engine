<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Ядро з калькулятором «за площею» без ціни й без кроку площі, з додатковими полями заявки у формі
 * (app.calculator.area + app.lead_fields паку). Окремий кеш, щоб не перетиратися з основним тестовим контейнером.
 */
final class CalcAreaKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.calculator.area', ['quote' => false, 'quantity' => false, 'min' => 1, 'max' => 100000, 'lead_fields' => true]);
        $container->parameters()->set('app.lead_fields', [
            ['key' => 'route', 'label' => ['uk' => 'Маршрут', 'ru' => 'Маршрут'], 'type' => 'text', 'required' => true],
            ['key' => 'weight', 'label' => ['uk' => 'Вага', 'ru' => 'Вес'], 'type' => 'number', 'unit' => ['uk' => 'кг', 'ru' => 'кг']],
        ]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/calcarea';
    }
}
