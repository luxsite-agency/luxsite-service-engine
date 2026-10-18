<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\ServiceSite\LegacyUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * 301 зі старого сайту бренду для адрес першого рівня (/старий-слаг/). Мапа — у паку
 * (app.legacy_redirects), формат і логіка цілі — App\ServiceSite\LegacyUrls; основну роботу
 * робить LegacyUrlSubscriber ще до роутингу (там же вкладені шляхи, /ru/…, слеш, 410), цей
 * маршрут — страховка на випадок, коли підписник не спрацював (sub-request, не GET).
 */
final class LegacyRedirectController extends AbstractController
{
    public function __construct(private readonly LegacyUrls $legacy)
    {
    }

    #[Route(
        path: '/{legacy}/',
        name: 'sv_legacy_redirect',
        requirements: ['legacy' => '[a-z0-9-]+'],
        priority: -10,
        methods: ['GET', 'HEAD'],
        condition: "request.attributes.has('service_city')",
    )]
    public function redirectLegacy(Request $request, string $legacy): RedirectResponse
    {
        $target = $this->legacy->legacyTarget($request, '/' . $legacy . '/');
        if ($target === null) {
            throw $this->createNotFoundException('Такої сторінки не було.');
        }

        if (!str_contains($target, '?')) {
            $target .= $this->legacy->queryFor($request, (string) parse_url($target, PHP_URL_PATH));
        }
        $response = $this->redirect($target, 301);
        // 301 без заголовків кешу браузер тримає назавжди: коли мапа паку міняється (адреса стала
        // справжньою сторінкою), у того, хто вже ходив за старою, назавжди лишається редірект.
        // 14.10.2026 так сталося з /services/ після вмикання сторінки «Усі послуги».
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }
}
