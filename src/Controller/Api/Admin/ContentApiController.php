<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\AdminApi\ApiProblem;
use App\AdminApi\ContentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST контенту для менеджерів і скриптів (персональні токени, docs/admin-api.md). Логіка — ContentService (спільна з MCP).
 *
 *   GET   /api/admin/v1/{site}/{kind}                 список (kind: items|categories|districts|posts|prices|item-texts|category-texts)
 *   GET   /api/admin/v1/{site}/{kind}/{id}            один запис з усіма дозволеними полями
 *   PATCH /api/admin/v1/{site}/{kind}/{id}            JSON {поле: значення} лише з дозволених полів; відповідь — запис після змін
 *   POST  /api/admin/v1/{site}/item-texts             {item_id, city_id, …поля} — створити/оновити тексти послуги для міста
 *   POST  /api/admin/v1/{site}/category-texts         {category_id, city_id, …поля}
 *
 * Фільтри списків: ?city=<slug> (districts, prices, item-texts, category-texts), ?category=<id> (items), ?item=<id> (prices), ?q=<підрядок>.
 */
final class ContentApiController extends AbstractController
{
    public function __construct(private readonly ContentService $content)
    {
    }

    #[Route('/api/admin/v1/{site}/{kind}', name: 'api_admin_list', methods: ['GET'], requirements: ['kind' => ContentService::KINDS])]
    public function list(Request $request, string $site, string $kind): JsonResponse
    {
        return $this->run(fn () => $this->content->list($site, $kind, $request->query->all()));
    }

    #[Route('/api/admin/v1/{site}/{kind}/{id}', name: 'api_admin_get', methods: ['GET'], requirements: ['kind' => ContentService::KINDS, 'id' => '\d+'])]
    public function get(string $site, string $kind, int $id): JsonResponse
    {
        return $this->run(fn () => $this->content->get($site, $kind, $id));
    }

    #[Route('/api/admin/v1/{site}/{kind}/{id}', name: 'api_admin_patch', methods: ['PATCH'], requirements: ['kind' => ContentService::KINDS, 'id' => '\d+'])]
    public function patch(Request $request, string $site, string $kind, int $id): JsonResponse
    {
        return $this->run(fn () => $this->content->patch($site, $kind, $id, $this->body($request)));
    }

    #[Route('/api/admin/v1/{site}/{kind}', name: 'api_admin_upsert_texts', methods: ['POST'], requirements: ['kind' => 'item-texts|category-texts'])]
    public function upsertTexts(Request $request, string $site, string $kind): JsonResponse
    {
        return $this->run(function () use ($request, $site, $kind) {
            [$row, $created] = $this->content->upsertTexts($site, $kind, $this->body($request));

            return new JsonResponse($row, $created ? Response::HTTP_CREATED : Response::HTTP_OK);
        });
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || $data === []) {
            throw new ApiProblem('Тіло має бути непорожнім JSON-об’єктом', 400);
        }

        return $data;
    }

    /** ApiProblem → {"error": …} зі статусом; статус 200 («нічого не змінено») — теж повідомлення, не помилка. */
    private function run(callable $op): JsonResponse
    {
        try {
            $result = $op();

            return $result instanceof JsonResponse ? $result : $this->json($result);
        } catch (ApiProblem $e) {
            return $this->json(['error' => $e->getMessage()], $e->status);
        }
    }
}
