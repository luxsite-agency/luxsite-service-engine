<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\AdminApi\ApiProblem;
use App\AdminApi\RedirectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * REST менеджера редиректів і журналу 404 (персональні токени, docs/admin-api.md). Логіка — RedirectService (спільна з MCP).
 *
 *   GET    /api/admin/v1/{site}/redirects             список; фільтри ?city=<slug>, ?code=301, ?kind=manual|auto|pack, ?q=<підрядок>
 *   POST   /api/admin/v1/{site}/redirects             JSON {source, code?, target?, city?} → 201 нове / 200 замінене (ті самі перевірки, що в адмінці)
 *   DELETE /api/admin/v1/{site}/redirects/{id}        → 204
 *   GET    /api/admin/v1/{site}/not-found             журнал 404; ?city=<slug>, ?q=<підрядок>, ?limit=200
 */
final class RedirectApiController extends AbstractController
{
    public function __construct(private readonly RedirectService $redirects)
    {
    }

    #[Route('/api/admin/v1/{site}/redirects', name: 'api_admin_redirects_list', methods: ['GET'])]
    public function list(Request $request, string $site): JsonResponse
    {
        return $this->run(fn () => $this->redirects->list($site, $request->query->all()));
    }

    #[Route('/api/admin/v1/{site}/redirects', name: 'api_admin_redirects_create', methods: ['POST'])]
    public function create(Request $request, string $site): JsonResponse
    {
        return $this->run(function () use ($request, $site) {
            [$row, $created] = $this->redirects->create($site, $this->body($request));

            return new JsonResponse($row, $created ? Response::HTTP_CREATED : Response::HTTP_OK);
        });
    }

    #[Route('/api/admin/v1/{site}/redirects/{id}', name: 'api_admin_redirects_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(string $site, int $id): JsonResponse
    {
        return $this->run(function () use ($site, $id) {
            $this->redirects->delete($site, $id);

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    #[Route('/api/admin/v1/{site}/not-found', name: 'api_admin_not_found_list', methods: ['GET'])]
    public function notFound(Request $request, string $site): JsonResponse
    {
        return $this->run(fn () => $this->redirects->notFound($site, $request->query->all()));
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
