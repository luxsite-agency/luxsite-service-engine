<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Шаблони бренду (22.10.2026): кілька брендів в одному розгортанні з різною розміткою. Порядок пошуку імені:
 *
 *   var/theme/brands/<slug>/templates   живий дизайн бренду (під токеном)
 *   var/theme/templates                 живий дизайн розгортання (як і раніше — першим для бренду без своїх файлів)
 *   templates/site/brands/<slug>        пак: дизайн бренду
 *   → далі стандартний FilesystemLoader: templates/site (пак), templates (@engine)
 *
 * Бренд — з головного запиту (атрибут ServiceHostSubscriber), тож і підзапит сторінки помилки бачить свій бренд.
 * Лише імена services/** і partials/** без простору (@engine/…, @pack/… — повз). Ключ кешу — бренд + ім'я + md5 вмісту:
 * кожен бренд і кожна версія файлу — окремий клас Twig, скидати кеш не треба. Нема теки бренду — exists() = false,
 * і шаблони розгортання (живі, пак, рушій) працюють як до появи лоадера.
 */
#[AutoconfigureTag('twig.loader', ['priority' => 10])]
final class BrandTemplateLoader implements LoaderInterface
{
    private const ALLOWED = '~^(services|partials)/[A-Za-z0-9_./-]+\.twig$~';

    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getSourceContext(string $name): Source
    {
        $file = $this->find($name) ?? throw new LoaderError(sprintf('Шаблону бренду «%s» нема.', $name));

        return new Source((string) file_get_contents($file), $name, $file);
    }

    public function getCacheKey(string $name): string
    {
        $file = $this->find($name) ?? throw new LoaderError(sprintf('Шаблону бренду «%s» нема.', $name));

        return 'brand:' . $file . ':' . md5_file($file);
    }

    public function isFresh(string $name, int $time): bool
    {
        // ключ кешу вже містить хеш вмісту: змінений файл = інший клас
        return true;
    }

    public function exists(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /** Файл шаблону для поточного бренду або null (бренду нема, ім'я поза дозволеними, файлу нема в шарах бренду) */
    private function find(string $name): ?string
    {
        if (!preg_match(self::ALLOWED, $name) || str_contains($name, '..')) {
            return null;
        }
        $site = $this->requests->getMainRequest()?->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        if (!$site instanceof ServiceSite || !preg_match('~^[a-z0-9-]+$~', $site->getSlug())) {
            return null;
        }
        $slug = $site->getSlug();
        $liveBrand = $this->projectDir . '/var/theme/brands/' . $slug . '/templates/';
        $packBrand = $this->projectDir . '/templates/site/brands/' . $slug . '/';
        if (!is_dir($liveBrand) && !is_dir($packBrand)) {
            return null;
        }
        foreach ([$liveBrand, $this->projectDir . '/var/theme/templates/', $packBrand] as $dir) {
            if (is_file($dir . $name)) {
                return $dir . $name;
            }
        }

        return null;
    }
}
