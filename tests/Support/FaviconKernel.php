<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** Ядро з іконкою теми: /favicon.ico має вести на app.theme.favicon_ico */
final class FaviconKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.theme', ['key' => 'fav-test', 'favicon_ico' => 'themes/fav-test/favicon.ico']);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/favicon';
    }
}
