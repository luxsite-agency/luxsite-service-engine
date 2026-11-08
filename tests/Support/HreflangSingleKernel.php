<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** Ядро з app.hreflang_single: true (розгортання одномовних брендів, satellites). Окремий кеш. */
final class HreflangSingleKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.hreflang_single', true);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/hreflangsingle';
    }
}
