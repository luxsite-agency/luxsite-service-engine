<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class FixedPagesKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.fixed_pages', ['faq' => false, 'contacts' => '/our-office/', 'reviews' => false, 'privacy' => '/legal/', 'terms' => false]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/fixed-pages';
    }
}
