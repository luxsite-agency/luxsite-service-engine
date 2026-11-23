<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** Схема slug, мови [uk, ru, en] */
final class SlugUkRuEnKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.locales', ['uk', 'ru', 'en']);
        $container->parameters()->set('app.catalog.url_scheme', 'slug');
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/slug-uk-ru-en';
    }
}
