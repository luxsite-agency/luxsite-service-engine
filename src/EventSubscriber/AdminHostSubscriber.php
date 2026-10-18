<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Адмінка живе ТІЛЬКИ на службовому домені (ADMIN_HOST, напр. admin.example.com).
 * На міських доменах /admin і /login не існують (404) — менша поверхня атак
 * і жодних слідів адмінки в індексації міст.
 */
final class AdminHostSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(env: 'ADMIN_HOST')]
        private readonly string $adminHost,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 40]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        // /logout НЕ блокуємо: він потрібен і Google-юзерам на міських доменах
        // /api/admin — REST адмінки (персональні токени), теж лише на службовому хості
        $isAdminPath = str_starts_with($path, '/admin') || str_starts_with($path, '/api/admin') || $path === '/mcp' || $path === '/login';

        if ($isAdminPath && strtolower($request->getHost()) !== strtolower($this->adminHost)) {
            throw new NotFoundHttpException('Not found.');
        }
    }
}
