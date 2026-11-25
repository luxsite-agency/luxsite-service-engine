<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class EnglishCatalogKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.locales', ['en']);
        $container->parameters()->set('app.catalog.url_scheme', 'slug');
        $container->parameters()->set('app.pages', true);
        $container->parameters()->set('app.schema_country', 'US');
        $container->parameters()->set('app.currency', 'USD');
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/english-catalog';
    }
}
