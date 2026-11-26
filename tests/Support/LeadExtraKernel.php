<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** Пак дозволяє довільні поля форми заявки (app.lead.extra_fields): ключ рядком або {key, label} */
final class LeadExtraKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.lead.extra_fields', [['key' => 'address', 'label' => 'Адреса будинку'], 'topic', 'showing_day']);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/lead-extra';
    }
}
