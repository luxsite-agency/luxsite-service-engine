<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Ядро для тестів захисту форм: app.lead_spam з українськими кодами країни і малим лімітом з IP (3 за годину), CRM —
 * RecordingCrm (видно, чи пішла заявка в CRM). Окремий кеш, щоб не перетиратися з основним тестовим контейнером.
 */
final class LeadSpamKernel extends Kernel
{
    public const array SPAM = ['phone_country_codes' => ['380', '0'], 'ip_per_hour' => 3, 'dedup_seconds' => 60];

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.lead_spam', self::SPAM);
        $container->parameters()->set('app.crm', ['system' => RecordingCrm::SYSTEM]);
        $container->services()->set(RecordingCrm::class)->autowire()->autoconfigure()->tag('app.lead_crm');
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/leadspam';
    }
}
