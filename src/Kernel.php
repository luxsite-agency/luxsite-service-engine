<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

namespace App;

use App\ServiceSite\CatalogUrls;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    // дефолтні імпорти трейта під іншими іменами, щоб дописати до них пак (як у shop-engine)
    use MicroKernelTrait {
        configureContainer as private configureContainerDefaults;
        configureRoutes as private configureRoutesDefaults;
    }

    /**
     * var/theme/templates — живі шаблони під токеном (ThemeService), перший шлях Twig; FilesystemLoader кидає виняток,
     * якщо теки нема, тож гарантуємо її (на сервері var/theme — симлінк на shared/theme, на стенді — mount).
     */
    public function boot(): void
    {
        $dir = $this->getProjectDir() . '/var/theme/templates';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        parent::boot();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * Параметри конкретного сайту (пак: тема, CRM (app.crm, app.pipedrive), редіректи зі старого сайту, схема URL каталогу…) —
     * у config/site/parameters.local.yaml поза git (деплой копіює туди config/parameters.yaml паку).
     * Імпорт після services.yaml, щоб перекрити його дефолти; у тестах не вантажиться.
     * Дзеркало config/store/parameters.local.yaml у shop-engine.
     */
    private function configureContainer(ContainerConfigurator $container): void
    {
        $this->configureContainerDefaults($container);

        if ('test' !== $this->environment) {
            $container->import($this->getConfigDir().'/site/parameters.local.yaml', null, 'not_found');
        }
    }

    /**
     * Маршрути каталогу залежать від паку: один сайт живе на /services/<категорія>/<послуга>/
     * (nested), інший — на /services/<послуга>/ (flat), третій (з Joomla) — на /<категорія>/<послуга>/
     * від кореня (root). Усі схеми в індексі Google,
     * тому вибір — параметр app.catalog.url_scheme, а не константа в атрибутах контролера.
     */
    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $this->configureRoutesDefaults($routes);

        $scheme = (string) $this->container->getParameter('app.catalog.url_scheme');
        if (!\in_array($scheme, ['flat', 'nested', 'root'], true)) {
            throw new \InvalidArgumentException(sprintf('app.catalog.url_scheme: очікується flat, nested або root, отримано «%s».', $scheme));
        }
        // префікс блогу стає сегментом маршрутів (/%app.blog_prefix%/…): лише [a-z0-9-] і не інший фіксований розділ
        $blog = (string) $this->container->getParameter('app.blog_prefix');
        if (!preg_match('/^[a-z0-9-]+$/', $blog)) {
            throw new \InvalidArgumentException(sprintf('app.blog_prefix: очікується сегмент [a-z0-9-] (blog, news…), отримано «%s».', $blog));
        }
        if (\in_array($blog, array_diff(CatalogUrls::RESERVED_SEGMENTS, ['blog', 'news']), true)) {
            throw new \InvalidArgumentException(sprintf('app.blog_prefix: «%s» — зарезервований сегмент іншого розділу сайту.', $blog));
        }
        $routes->import($this->getConfigDir().'/routes/catalog/'.$scheme.'.yaml');
    }
}
