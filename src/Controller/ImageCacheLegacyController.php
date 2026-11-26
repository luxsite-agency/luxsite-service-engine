<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller;

use App\Imagine\FormatExtensionResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Старі адреси кешу зображень (до 26.11.2026 — за шляхом оригіналу: `/media/cache/item_hero/…/x.jpg` з тілом WEBP).
 * Сюди запит доходить, лише коли файлу на диску нема (веб-сервер віддає наявний файл сам): тоді 301 на генерацію
 * `/media/cache/resolve/…`, яка покладе файл під новим іменем (`x.jpg.webp`) і поведе на нього. Адреса, якій нове ім'я
 * не потрібне, — звичайний 404, як і був.
 */
final class ImageCacheLegacyController
{
    public function __construct(private readonly FormatExtensionResolver $resolver)
    {
    }

    #[Route(
        path: '/media/cache/{filter}/{path}',
        name: 'app_image_cache_legacy',
        requirements: ['filter' => '(?!resolve$)[A-Za-z0-9_-]+', 'path' => '.+'],
        methods: ['GET', 'HEAD'],
        priority: -10,
    )]
    public function __invoke(string $filter, string $path): Response
    {
        if (str_contains($path, '..') || $this->resolver->cachePath($path, $filter) === $path) {
            throw new NotFoundHttpException();
        }

        return new RedirectResponse('/media/cache/resolve/' . $filter . '/' . implode('/', array_map('rawurlencode', explode('/', $path))), Response::HTTP_MOVED_PERMANENTLY);
    }
}
