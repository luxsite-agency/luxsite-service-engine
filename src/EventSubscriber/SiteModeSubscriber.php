<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ServiceSite;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Маршрути за режимом сайту (ServiceSite.mode, 22.10.2026), після роутера (19).
 *
 * - content: **allowlist** точних імен (CONTENT_ROUTES). Будь-який інший маршрут сайту (sv_*, вхід Google для відгуків)
 *   → 404: новий сервісний маршрут, доданий у рушій пізніше, на контентних сайтах сам не з'явиться.
 * - services: маршрути, яких у сервісних сайтів не було (CONTENT_ONLY_ROUTES: рубрики, автори) → 404, тож сервісний
 *   сайт поводиться як до появи режиму.
 * Службові маршрути (студія, Liip, адмінка) не чіпаються.
 */
final class SiteModeSubscriber implements EventSubscriberInterface
{
    /** Що відкрито контентному сайту (канонічні імена, без .<мова>) */
    public const CONTENT_ROUTES = [
        'sv_home', 'sv_blog', 'sv_blog_page', 'sv_blog_post', 'sv_blog_category', 'sv_blog_category_page', 'sv_author',
        'sv_about', 'sv_contacts', 'sv_thanks', 'sv_privacy', 'sv_terms', 'sv_sitemap', 'sv_robots', 'sv_lead',
    ];

    /** Маршрути лише для контентного сайту */
    public const CONTENT_ONLY_ROUTES = ['sv_blog_category', 'sv_blog_category_page', 'sv_author'];

    /** Префікси імен маршрутів, які стосуються сайту (решта — службові) */
    private const SITE_ROUTE_PREFIXES = ['sv_', 'connect_google'];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 19]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        if (!$site instanceof ServiceSite) {
            return;
        }
        $route = (string) ($request->attributes->get('_canonical_route') ?? $request->attributes->get('_route') ?? '');
        if (!self::allowed($site, $route)) {
            throw new NotFoundHttpException(sprintf('Маршрут «%s» вимкнено в режимі сайту «%s».', $route, $site->getMode()));
        }
    }

    public static function allowed(ServiceSite $site, string $route): bool
    {
        if ($site->isContent()) {
            $isSiteRoute = false;
            foreach (self::SITE_ROUTE_PREFIXES as $prefix) {
                $isSiteRoute = $isSiteRoute || str_starts_with($route, $prefix);
            }

            return !$isSiteRoute || \in_array($route, self::CONTENT_ROUTES, true);
        }

        return !\in_array($route, self::CONTENT_ONLY_ROUTES, true);
    }
}
