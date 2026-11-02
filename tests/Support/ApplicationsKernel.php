<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Ядро з формою анкети app.applications.translator (як у паку бюро перекладів) і шаблоном контактів з tests/Support/templates,
 * що підключає анкету. Окремий кеш, щоб не перетиратися з основним тестовим контейнером.
 */
final class ApplicationsKernel extends Kernel
{
    public const array FORM = [
        'title' => ['uk' => 'Анкета перекладача', 'ru' => 'Анкета переводчика'],
        'email' => 'hr@example.test',
        'required' => ['name', 'phone', 'email', 'languages'],
        'consent' => true,
        'files' => ['max_files' => 2, 'max_file_mb' => 1, 'max_total_mb' => 2, 'mime' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png']],
        'fields' => [
            'city' => ['label' => ['uk' => 'Місто', 'ru' => 'Город']],
            'languages' => ['label' => ['uk' => 'Мови', 'ru' => 'Языки']],
            'work' => ['label' => ['uk' => 'Види робіт', 'ru' => 'Виды работ'], 'type' => 'list', 'options' => [
                'written' => ['uk' => 'Письмовий переклад', 'ru' => 'Письменный перевод'],
                'oral' => ['uk' => 'Усний переклад', 'ru' => 'Устный перевод'],
                'editing' => ['uk' => 'Редагування', 'ru' => 'Редактура'],
            ]],
            'about' => ['label' => ['uk' => 'Про себе', 'ru' => 'О себе'], 'type' => 'textarea'],
        ],
    ];

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);
        $container->parameters()->set('app.applications', ['translator' => self::FORM]);
        $container->parameters()->set('app.lead_files_dir', '%kernel.project_dir%/var/test-lead-files');
        $container->extension('twig', ['paths' => ['%kernel.project_dir%/tests/Support/templates' => null]]);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '/applications';
    }
}
