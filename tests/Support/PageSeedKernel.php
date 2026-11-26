<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** Англомовний сайт-каталог на сторінках app.pages: поля запису з паку (app.custom_fields.page), обкладинка й галерея з seed */
final class PageSeedKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.locales', ['en']);
        $container->parameters()->set('app.pages', true);
        $container->parameters()->set('app.custom_fields', ['page' => [
            ['key' => 'price', 'label' => ['uk' => 'Ціна', 'en' => 'Price'], 'filter' => true],
            ['key' => 'village', 'label' => ['uk' => 'Селище', 'en' => 'Village']],
            ['key' => 'note', 'label' => ['uk' => 'Примітка', 'en' => 'Note'], 'localized' => true],
        ]]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/page-seed';
    }
}
