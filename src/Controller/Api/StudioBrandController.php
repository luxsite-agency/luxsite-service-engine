<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Api;

use App\EventSubscriber\ServiceHostSubscriber;
use App\Repository\ServiceSiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Приймач content-studio на адмін-хості (22.10.2026): https://<ADMIN_HOST>/api/studio/<slug>/… — бренд зі шляху, а не з
 * домену, тож мережа брендів має один ендпойнт, і він не світиться на кожному домені (бренд з
 * studio_on_public_host: false на своїх доменах віддає 404). Лише персональні токени з доступом до бренду; сама робота —
 * StudioArticleController (той самий код, що й на доменах бренду).
 */
#[Route('/api/studio/{site}', name: 'api_studio_brand_', requirements: ['site' => '[a-z0-9-]+'], priority: 21, condition: "request.getHost() == env('ADMIN_HOST')")]
final class StudioBrandController extends AbstractController
{
    public function __construct(private readonly ServiceSiteRepository $sites)
    {
    }

    #[Route('/ping', name: 'ping', methods: ['GET'])]
    public function ping(Request $request, string $site): Response
    {
        return $this->delegate($request, $site, 'ping', []);
    }

    #[Route('/articles/{externalId}', name: 'article_put', methods: ['PUT'], requirements: ['externalId' => '[A-Za-z0-9_-]{1,64}'])]
    public function put(Request $request, string $site, string $externalId): Response
    {
        return $this->delegate($request, $site, 'put', ['externalId' => $externalId]);
    }

    #[Route('/pages', name: 'pages', methods: ['GET'])]
    public function pages(Request $request, string $site): Response
    {
        return $this->delegate($request, $site, 'pages', []);
    }

    /** @param array<string, string> $args */
    private function delegate(Request $request, string $slug, string $action, array $args): Response
    {
        $brand = $this->sites->findActiveBySlug($slug);
        if ($brand === null) {
            return $this->json(['error' => 'Сайт не знайдено: ' . $slug], Response::HTTP_NOT_FOUND);
        }

        return $this->forward(StudioArticleController::class . '::' . $action, $args + [
            ServiceHostSubscriber::ATTR_SITE => $brand,
            StudioArticleController::ADMIN_HOST_ATTR => true,
        ]);
    }
}
