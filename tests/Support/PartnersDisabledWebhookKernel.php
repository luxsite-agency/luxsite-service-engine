<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class PartnersDisabledWebhookKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.partners', ['enabled' => false]);
        $container->services()->set(PartnerWebhookMock::class);
        $container->services()->set('http_client', MockHttpClient::class)->args([service(PartnerWebhookMock::class)]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/partners-disabled-webhook';
    }
}
