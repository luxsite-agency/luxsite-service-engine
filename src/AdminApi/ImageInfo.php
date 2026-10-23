<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use App\Entity\ServiceSite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Зображення записів у відповідях admin API: які види записів мають фото (Vich-мапінги config/packages/vich_uploader.yaml)
 * і блок `image` для GET/PUT (url, name, alt, alt_ru, width, height, bytes). Без операцій — ними займається ImageService.
 */
final class ImageInfo
{
    /** Вид запису API → тека в public/media (= uri_prefix мапінгу Vich). */
    public const array MEDIA_DIR = [
        'items' => 'service_item', 'categories' => 'service_category', 'posts' => 'service_post',
        'works' => 'service_work', 'team' => 'service_team', 'reviews' => 'service_review',
    ];
    /** Однина з брифу → множина, як у решті API. */
    public const array ALIASES = ['item' => 'items', 'category' => 'categories', 'post' => 'posts', 'work' => 'works', 'review' => 'reviews'];
    public const string KINDS = 'items|categories|posts|works|team|reviews';
    public const string ROUTE_KINDS = self::KINDS . '|item|category|post|work|review';

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    public static function kind(string $kind): string
    {
        $kind = self::ALIASES[$kind] ?? $kind;
        if (!isset(self::MEDIA_DIR[$kind])) {
            throw new ApiProblem('Зображення є лише у: ' . str_replace('|', ', ', self::KINDS) . ' (можна в однині: item, category, post, work, team, review)', 400);
        }

        return $kind;
    }

    public static function hasImage(string $kind): bool
    {
        return isset(self::MEDIA_DIR[self::ALIASES[$kind] ?? $kind]);
    }

    public function path(string $kind, string $name): string
    {
        return $this->projectDir . '/public/media/' . self::MEDIA_DIR[self::kind($kind)] . '/' . $name;
    }

    /** @return array<string, mixed>|null null = фото нема */
    public function describe(string $kind, object $e, ServiceSite $site): ?array
    {
        $name = $e->getImageName();
        if ($name === null || $name === '') {
            return null;
        }
        $file = $this->path($kind, $name);
        $size = @getimagesize($file);
        $bytes = @filesize($file);

        return [
            'url' => 'https://' . $site->getApexDomain() . '/media/' . self::MEDIA_DIR[self::kind($kind)] . '/' . $name,
            'name' => $name,
            'alt' => $e->getImageAlt(),
            'alt_ru' => method_exists($e, 'getImageAltRu') ? $e->getImageAltRu() : null,
            'width' => $size === false ? null : $size[0],
            'height' => $size === false ? null : $size[1],
            'bytes' => $bytes === false ? null : $bytes,
        ];
    }
}
