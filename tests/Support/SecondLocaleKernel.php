<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** Ядро розгортання без мовних префіксів (satellites): app.locales [en, uk, ru], app.locale_prefix: false. Окремий кеш. */
final class SecondLocaleKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.locales', ['en', 'uk', 'ru']);
        $container->parameters()->set('app.locale_prefix', false);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/secondlocale';
    }
}
