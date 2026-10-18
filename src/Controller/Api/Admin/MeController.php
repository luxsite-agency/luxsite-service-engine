<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\AdminApi\ContentService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/admin/v1/me — хто я, який токен (без значення), дозволи, доступні сайти з містами. Дозволу не вимагає:
 * будь-який чинний токен. Перший запит для перевірки токена (docs/admin-api.md).
 */
final class MeController extends AbstractController
{
    public function __construct(private readonly ContentService $content)
    {
    }

    #[Route('/api/admin/v1/me', name: 'api_admin_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        return $this->json($this->content->me($user instanceof User ? $user : null));
    }
}
