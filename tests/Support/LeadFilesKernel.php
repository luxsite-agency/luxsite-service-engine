<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Ядро з увімкненими файлами заявок (app.lead_files.enabled) і власною приватною текою var/test-lead-files; ліміти менші за
 * типові, щоб тест не ганяв мегабайти. Окремий кеш, щоб не перетиратися з основним тестовим контейнером.
 */
final class LeadFilesKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.lead_files', ['enabled' => true, 'max_files' => 3, 'max_file_mb' => 1, 'max_total_mb' => 2]);
        $container->parameters()->set('app.lead_files_dir', '%kernel.project_dir%/var/test-lead-files');
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/leadfiles';
    }
}
