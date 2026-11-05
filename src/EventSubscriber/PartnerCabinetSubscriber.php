<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\PartnerCabinetAccess;
use App\ServiceSite\Partners\PartnersConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

final class PartnerCabinetSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PartnersConfig $config, private readonly PartnerCabinetAccess $access)
    {
    }

    /**
     * Адреса кабінету — сегмент /partner цілком (/partner, /partner/…). Не префікс рядка: /partners/, /partnery/,
     * /partnership/ — звичайні сторінки сайтів, їх кабінет не чіпає.
     */
    public static function isCabinetPath(string $path): bool
    {
        return $path === '/partner' || str_starts_with($path, '/partner/');
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['configure', 20], ['authorize', 1]], KernelEvents::RESPONSE => 'protectResponse'];
    }

    public function configure(RequestEvent $event): void
    {
        // лише головний запит: сторінка помилки — підзапит з тією самою адресою, повторний виняток зламав би 404/403
        if (!$event->isMainRequest() || !self::isCabinetPath($event->getRequest()->getPathInfo())) {
            return;
        }
        // Після маршрутизатора, перед LocaleListener та firewall: вимкнений модуль завжди 404.
        if (!$this->config->enabled) {
            throw new NotFoundHttpException();
        }
        $event->getRequest()->attributes->set('_locale', $this->config->cabinetLocale);
    }

    public function authorize(RequestEvent $event): void
    {
        if ($event->isMainRequest() && self::isCabinetPath($event->getRequest()->getPathInfo())) {
            $this->access->partner();
        }
    }

    public function protectResponse(ResponseEvent $event): void
    {
        if (self::isCabinetPath($event->getRequest()->getPathInfo())) {
            $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
            $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
