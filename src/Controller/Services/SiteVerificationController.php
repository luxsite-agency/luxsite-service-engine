<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\ServiceSite\ServiceCityAware;
use App\Settings\SiteSettingKeys;
use App\Settings\SiteSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Файли підтвердження сайту в Google Search Console (google<hex>.html) і Bing (BingSiteAuth.xml) — з налаштувань бренду
 * в адмінці, а не з public рушія: один файл на всі розгортання зв'язував би мережу сайтів в одному акаунті Google.
 * Файл є лише на хостах бренду, якому його задано (апекс і міста); на інших — 404. Без постійного кешу: SiteSettings
 * читає БД на запит, тож збережене в адмінці діє одразу; max-age короткий, щоб видалений файл не жив у CDN.
 */
final class SiteVerificationController extends AbstractController
{
    use ServiceCityAware;

    private const int MAX_AGE = 300;

    public function __construct(private readonly SiteSettings $settings)
    {
    }

    #[Route(
        path: '/{file}',
        name: 'sv_google_verification',
        requirements: ['file' => 'google[0-9a-f]{8,32}\.html'],
        methods: ['GET', 'HEAD'],
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function google(Request $request, string $file): Response
    {
        $site = $this->serviceSite($request);
        if (!\in_array($file, SiteSettingKeys::googleVerificationFiles($this->settings->get($site, SiteSettingKeys::GOOGLE_VERIFICATION_FILES)), true)) {
            throw $this->createNotFoundException('Файл підтвердження не задано цьому бренду.');
        }

        return $this->short('google-site-verification: ' . $file, 'text/html; charset=UTF-8');
    }

    #[Route(
        path: '/BingSiteAuth.xml',
        name: 'sv_bing_site_auth',
        methods: ['GET', 'HEAD'],
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function bing(Request $request): Response
    {
        $code = $this->settings->valid($this->serviceSite($request), SiteSettingKeys::BING_SITE_AUTH);
        if (null === $code) {
            throw $this->createNotFoundException('BingSiteAuth.xml не задано цьому бренду.');
        }

        return $this->short("<?xml version=\"1.0\"?>\n<users>\n\t<user>" . $code . "</user>\n</users>\n", 'application/xml; charset=UTF-8');
    }

    private function short(string $body, string $type): Response
    {
        $response = new Response($body, 200, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);

        return $response;
    }
}
