<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Ядро з обов'язковими додатковими полями заявки паку (app.lead_fields: маршрут — required, вага). Окремий кеш, щоб не
 * перетиратися з основним тестовим контейнером.
 */
final class LeadFieldsKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.lead_fields', [
            ['key' => 'route', 'label' => ['uk' => 'Маршрут', 'ru' => 'Маршрут'], 'type' => 'text', 'required' => true],
            ['key' => 'weight', 'label' => ['uk' => 'Вага', 'ru' => 'Вес'], 'type' => 'number', 'unit' => ['uk' => 'кг', 'ru' => 'кг']],
        ]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/leadfields';
    }
}
