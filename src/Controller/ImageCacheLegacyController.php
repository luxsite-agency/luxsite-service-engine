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
 * `/media/cache/resolve/…`, яка покладе файл під новим іменем (`x.jpg.webp`) і поведе на нього. Адреса runtime-конфігу
 * (`rc/<хеш>/…`) — 301 прямо на файл нового імені, якщо він є, інакше 404 (параметрів фільтра для генерації в ній нема). Адреса, якій нове ім'я
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
        $cachePath = $this->resolver->cachePath($path, $filter);
        if (str_contains($path, '..') || $cachePath === $path) {
            throw new NotFoundHttpException();
        }
        // runtime-конфіг паку (rc/<хеш>/…): resolve для нього вимагає filters[...] у query, якого стара адреса не має (→ 400),
        // тож згенерувати файл звідси нема з чого. Файл під новим ім'ям уже є — ведемо прямо на нього; нема — 404, як до 26.11.2026
        if (str_starts_with($path, 'rc/')) {
            if (!$this->resolver->isStored($path, $filter)) {
                throw new NotFoundHttpException();
            }

            return new RedirectResponse('/media/cache/' . $filter . '/' . self::encode($cachePath), Response::HTTP_MOVED_PERMANENTLY);
        }

        return new RedirectResponse('/media/cache/resolve/' . $filter . '/' . self::encode($path), Response::HTTP_MOVED_PERMANENTLY);
    }

    private static function encode(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
