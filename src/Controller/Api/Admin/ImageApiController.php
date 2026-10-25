<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\AdminApi\ApiProblem;
use App\AdminApi\ImageInfo;
use App\AdminApi\ImageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Фото записів через REST (персональні токени, дозвіл content:write, docs/admin-api.md «Зображення»):
 *
 *   PUT|POST /api/admin/v1/{site}/{kind}/{id}/image   multipart/form-data поле file (лише POST — PHP не розбирає multipart
 *                                                     у PUT) АБО JSON {url} АБО {data (base64), filename}; необов'язкові alt, alt_ru
 *   DELETE   /api/admin/v1/{site}/{kind}/{id}/image   прибрати файл і поля alt
 *
 * kind: items, categories, posts, works, team, reviews (або в однині). Відповідь: {site, kind, id, image: {url, name, alt,
 * alt_ru, width, height, bytes}}. Межі: ≤10 МБ, JPEG/PNG/WebP за вмістом, ≤8000 px; 413 — завеликий, 422 — відхилено,
 * 429 + Retry-After — ліміт завантажень на токен.
 */
final class ImageApiController extends AbstractController
{
    public function __construct(private readonly ImageService $images)
    {
    }

    #[Route('/api/admin/v1/{site}/{kind}/{id}/image', name: 'api_admin_image_put', methods: ['PUT', 'POST'], requirements: ['kind' => ImageInfo::ROUTE_KINDS, 'id' => '\d+'])]
    public function put(Request $request, string $site, string $kind, int $id): JsonResponse
    {
        return $this->run(function () use ($request, $site, $kind, $id) {
            $file = $request->files->get('file');
            $multipart = str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/');
            if ($multipart && !$file instanceof UploadedFile) {
                // понад post_max_size PHP мовчки викидає $_POST і $_FILES — без цієї перевірки було б 400 «нема поля file»
                $length = (int) $request->headers->get('Content-Length', '0');
                $max = self::iniBytes((string) ini_get('post_max_size'));
                if ($max > 0 && $length > $max) {
                    throw new ApiProblem(sprintf('Тіло запиту %d байт понад post_max_size PHP (%s): файл до %d МБ, або передайте url чи base64 у JSON', $length, ini_get('post_max_size'), intdiv(ImageService::MAX_BYTES, 1_000_000)), 413);
                }
            }
            if ($file instanceof UploadedFile || $request->files->count() > 0 || $multipart) {
                return $this->images->putUpload($site, $kind, $id, $file instanceof UploadedFile ? $file : null, $this->text($request->request->get('alt')), $this->text($request->request->get('alt_ru')));
            }
            $body = json_decode($request->getContent(), true);
            if (!\is_array($body)) {
                throw new ApiProblem('Очікується multipart/form-data з полем file або JSON {url} чи {data, filename}', 400);
            }
            $alt = $this->text($body['alt'] ?? null);
            $altRu = $this->text($body['alt_ru'] ?? null);
            if (isset($body['url'])) {
                return $this->images->putUrl($site, $kind, $id, (string) $body['url'], $alt, $altRu);
            }
            if (isset($body['data'])) {
                return $this->images->putBase64($site, $kind, $id, (string) $body['data'], $this->text($body['filename'] ?? null), $alt, $altRu);
            }
            throw new ApiProblem('У JSON потрібне url або data (base64)', 400);
        });
    }

    #[Route('/api/admin/v1/{site}/{kind}/{id}/image', name: 'api_admin_image_delete', methods: ['DELETE'], requirements: ['kind' => ImageInfo::ROUTE_KINDS, 'id' => '\d+'])]
    public function delete(string $site, string $kind, int $id): JsonResponse
    {
        return $this->run(fn () => $this->images->delete($site, $kind, $id));
    }

    /** 16M / 512K / 1G → байти (запис php.ini); 0 = без межі */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $n = (int) $value;

        return match ($unit) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    }

    private function text(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }

    private function run(callable $op): JsonResponse
    {
        try {
            return $this->json($op());
        } catch (ApiProblem $e) {
            return $this->json(['error' => $e->getMessage()], $e->status, $e->headers);
        }
    }
}
