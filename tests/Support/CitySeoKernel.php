<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Ядро з параметрами мультиміського паку, що прибирає копії апексу з міст: app.catalog.faq_city_fallback: false і
 * app.legal_noindex_cities: true. Окремий кеш, щоб не перетиратися з основним тестовим контейнером.
 */
final class CitySeoKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.catalog.faq_city_fallback', false);
        $container->parameters()->set('app.legal_noindex_cities', true);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/cityseo';
    }
}
