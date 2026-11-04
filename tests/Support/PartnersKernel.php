<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use App\ServiceSite\Partners\HostResolver;
use App\Settings\SecretBox;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/** Модуль партнерів і ZIP форми паку з окремим кешем контейнера. */
final class PartnersKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $services = $container->services();
        $services->set(PartnerWebhookMock::class);
        $services->set('http_client', MockHttpClient::class)->args([service(PartnerWebhookMock::class)]);
        $services->set(HostResolver::class, PartnerHostResolver::class);
        // Фіксований тестовий ключ; не використовуємо доступи оточення.
        $services->set(SecretBox::class)->args([base64_encode(str_repeat('p', 32))]);
        $container->parameters()->set('app.partners', ['enabled' => true, 'share_max' => 3, 'unrouted_notify' => true, 'zip_from_key' => 'zip_from', 'zip_to_key' => 'zip_to']);
        $container->parameters()->set('app.timezone', 'America/New_York');
        $container->parameters()->set('app.lead_fields', [
            ['key' => 'zip_from', 'label' => ['uk' => 'From ZIP'], 'type' => 'text'],
            ['key' => 'zip_to', 'label' => ['uk' => 'To ZIP'], 'type' => 'text'],
        ]);
        $container->parameters()->set('app.lead_consent', ['enabled' => true,
            'text' => ['uk' => 'Погоджуюсь на передачу даних згідно з {policy}'], 'policy_text' => ['uk' => 'політикою конфіденційності']]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/partners';
    }
}
