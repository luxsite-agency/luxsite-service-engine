<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** Розгортання uk + ru + en (glemanlas): uk без префікса, /ru/ і /en/; каталог від кореня, сторінки app.pages */
final class TrilingualKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.locales', ['uk', 'ru', 'en']);
        $container->parameters()->set('app.catalog.url_scheme', 'slug');
        $container->parameters()->set('app.pages', true);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/trilingual';
    }
}
