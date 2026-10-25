<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/** Ядро з параметром паку app.html.strip_comments: false — сторінки з коментарями, як до чистки (для тестів «до/після»). */
final class HtmlKeepCommentsKernel extends Kernel
{
    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.html', ['strip_comments' => false]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/keepcomments';
    }
}
