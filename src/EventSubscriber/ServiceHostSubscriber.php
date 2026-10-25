<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\ServiceSite\ServiceHostResolver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * До роутингу (пріоритет 35: після AdminHostSubscriber 40, перед SiteLocaleSubscriber 34, LegacyUrlSubscriber 33 і RouterListener 32)
 * резолвить хости сервісних сайтів і кладе тенант у request attributes.
 * Роути сервісного профілю мають `priority: 10` + condition на цей атрибут —
 * на хостах міських сайтів condition хибний і роутинг працює як раніше,
 * жоден існуючий екшен не змінюється.
 */
final class ServiceHostSubscriber implements EventSubscriberInterface
{
    public const ATTR_SITE = 'service_site';
    public const ATTR_CITY = 'service_city';

    public function __construct(
        private readonly ServiceHostResolver $resolver,
        #[Autowire(env: 'ADMIN_HOST')]
        private readonly string $adminHost,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 35]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $host = strtolower($request->getHost());

        // Адмін-хост обслуговує тільки адмінку — сервісний резолв там не потрібен
        if ($host === strtolower($this->adminHost)) {
            return;
        }

        $resolved = $this->resolver->resolveByHost($host);
        if ($resolved === null) {
            return;
        }

        $request->attributes->set(self::ATTR_SITE, $resolved['site']);
        $request->attributes->set(self::ATTR_CITY, $resolved['city']);
    }
}
