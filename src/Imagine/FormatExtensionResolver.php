<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Imagine;

use Liip\ImagineBundle\Binary\BinaryInterface;
use Liip\ImagineBundle\Exception\Imagine\Filter\NonExistingFilterException;
use Liip\ImagineBundle\Imagine\Cache\Resolver\ResolverInterface;
use Liip\ImagineBundle\Imagine\Filter\FilterConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Адреса файлу в кеші LiipImagine з розширенням формату фільтра (26.11.2026). Liip кешує результат за шляхом оригіналу,
 * тож фільтр з `format: webp` клав WEBP у `….jpg`, а веб-сервер (тип за розширенням) віддавав його як image/jpeg; так само
 * PNG через jpg-фільтр ішов як image/png. Тут до шляху дописується розширення формату, коли воно інше:
 * `x.jpg` → `x.jpg.webp`, `x.png` → `x.png.jpg`; `x.jpg`/`x.jpeg` у jpg-фільтрі й `x.webp` у webp-фільтрі не змінюються.
 * Дописуємо, а не замінюємо (вбудований Liip FormatExtensionResolver замінює): `a.png` і `a.jpg` однієї теки не зливаються
 * в один файл кешу, а адреси, де тип уже правильний, лишаються як були.
 *
 * Формат із runtime-конфігу (`imagine_filter('x', {format: …})`) сюди не доходить — у шляху лише хеш; формат задавати
 * у filter_sets. Старі адреси без файлу на диску веде на генерацію ImageCacheLegacyController.
 */
#[AsDecorator('liip_imagine.cache.resolver.default')]
final class FormatExtensionResolver implements ResolverInterface
{
    /** Розширення, які веб-сервер віддає тим самим типом, що й формат */
    private const array SAME_TYPE = ['jpeg' => 'jpg'];

    public function __construct(
        #[AutowireDecorated]
        private readonly ResolverInterface $inner,
        #[Autowire(service: 'liip_imagine.filter.configuration')]
        private readonly FilterConfiguration $filters,
    ) {
    }

    public function isStored($path, $filter): bool
    {
        return $this->inner->isStored($this->cachePath((string) $path, (string) $filter), $filter);
    }

    public function resolve($path, $filter): string
    {
        return $this->inner->resolve($this->cachePath((string) $path, (string) $filter), $filter);
    }

    public function store(BinaryInterface $binary, $path, $filter): void
    {
        $this->inner->store($binary, $this->cachePath((string) $path, (string) $filter), $filter);
    }

    /** Разом із файлом нового імені прибираємо й старий (до 26.11.2026 — за шляхом оригіналу) */
    public function remove(array $paths, array $filters): void
    {
        $all = [];
        foreach ($paths as $path) {
            $all[] = (string) $path;
            foreach ($filters as $filter) {
                $all[] = $this->cachePath((string) $path, (string) $filter);
            }
        }
        $this->inner->remove(array_values(array_unique($all)), $filters);
    }

    /** Шлях файлу в кеші для фільтра: оригінал або оригінал + «.<формат>», коли розширення не відповідає формату. */
    public function cachePath(string $path, string $filter): string
    {
        try {
            $format = strtolower((string) ($this->filters->get($filter)['format'] ?? ''));
        } catch (NonExistingFilterException) {
            return $path;
        }
        if ($format === '') {
            return $path;
        }
        $format = self::SAME_TYPE[$format] ?? $format;
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return (self::SAME_TYPE[$extension] ?? $extension) === $format ? $path : $path . '.' . $format;
    }
}
