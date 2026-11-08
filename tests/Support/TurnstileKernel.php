<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Ядро з увімкненим Cloudflare Turnstile (app.lead_spam.turnstile.site_key; секрет тест кладе в $_SERVER[TURNSTILE_SECRET])
 * і підмінним HTTP-клієнтом (TurnstileVerifyMock): siteverify відповідає success за токеном віджета «ok». Окремий кеш.
 */
final class TurnstileKernel extends Kernel
{
    public const SITE_KEY = '1x00000000000000000000AA';

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.lead_spam', ['turnstile' => ['site_key' => self::SITE_KEY, 'secret_env' => 'TURNSTILE_SECRET']]);
        $container->services()->set(TurnstileVerifyMock::class);
        $container->extension('framework', ['http_client' => ['mock_response_factory' => TurnstileVerifyMock::class]]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/turnstile';
    }
}
