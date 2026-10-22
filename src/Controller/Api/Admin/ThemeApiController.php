<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\AdminApi\ApiProblem;
use App\AdminApi\ThemeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST живої правки дизайну (дозвіл theme:write, docs/admin-api.md «Дизайн»). Логіка — ThemeService (спільна з MCP).
 *
 *   GET    /api/admin/v1/{site}/theme                         що можна перекрити, що перекрито (live|pack|engine), theme.css
 *   GET    /api/admin/v1/{site}/theme/{path}                  поточний текст файлу (живий, з паку або рушія) + історія
 *   PUT    /api/admin/v1/{site}/theme/{path}                  тіло — текст файлу (text/plain або JSON {"content": …})
 *   DELETE /api/admin/v1/{site}/theme/{path}                  прибрати живе перекриття
 *   POST   /api/admin/v1/{site}/theme/{path}/revert           JSON {"version": "YYYYMMDD-HHMMSS"}
 *
 * 422 — шаблон не пройшов страж (ThemeTemplateGuard: синтаксис і allowlist LiveThemePolicy) або не рендериться;
 * 409 + Retry-After — живу тему зараз змінює інший запит (замок ThemeService).
 */
final class ThemeApiController extends AbstractController
{
    private const string PATH_RE = '(services|partials)/[A-Za-z0-9_./-]+\.twig|theme\.css';

    public function __construct(private readonly ThemeService $theme)
    {
    }

    #[Route('/api/admin/v1/{site}/theme', name: 'api_admin_theme_list', methods: ['GET'])]
    public function list(string $site): JsonResponse
    {
        return $this->run(fn () => $this->theme->list($site));
    }

    #[Route('/api/admin/v1/{site}/theme/{path}', name: 'api_admin_theme_get', methods: ['GET'], requirements: ['path' => self::PATH_RE])]
    public function get(string $site, string $path): JsonResponse
    {
        return $this->run(fn () => $this->theme->get($site, $path));
    }

    #[Route('/api/admin/v1/{site}/theme/{path}', name: 'api_admin_theme_put', methods: ['PUT'], requirements: ['path' => self::PATH_RE])]
    public function put(Request $request, string $site, string $path): JsonResponse
    {
        return $this->run(function () use ($request, $site, $path) {
            $raw = $request->getContent();
            if (str_starts_with((string) $request->headers->get('Content-Type', ''), 'application/json')) {
                $data = json_decode($raw, true);
                if (!\is_array($data) || !\is_string($data['content'] ?? null)) {
                    throw new ApiProblem('JSON має містити поле content (рядок)', 400);
                }
                $raw = $data['content'];
            }

            return $this->theme->put($site, $path, $raw);
        });
    }

    #[Route('/api/admin/v1/{site}/theme/{path}', name: 'api_admin_theme_delete', methods: ['DELETE'], requirements: ['path' => self::PATH_RE])]
    public function delete(string $site, string $path): JsonResponse
    {
        return $this->run(fn () => $this->theme->delete($site, $path));
    }

    #[Route('/api/admin/v1/{site}/theme/{path}/revert', name: 'api_admin_theme_revert', methods: ['POST'], requirements: ['path' => self::PATH_RE])]
    public function revert(Request $request, string $site, string $path): JsonResponse
    {
        return $this->run(function () use ($request, $site, $path) {
            $data = json_decode($request->getContent(), true);

            return $this->theme->revert($site, $path, (string) ($data['version'] ?? ''));
        });
    }

    private function run(callable $op): JsonResponse
    {
        try {
            return $this->json($op());
        } catch (ApiProblem $e) {
            // 409 — живу тему зараз змінює інший запит (замок ThemeService): повторити за кілька секунд
            return $this->json(['error' => $e->getMessage()], $e->status, $e->status === 409 ? ['Retry-After' => '5'] : []);
        }
    }
}
