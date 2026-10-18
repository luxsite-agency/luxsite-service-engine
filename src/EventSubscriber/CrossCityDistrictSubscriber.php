<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\ServiceSite\CrossCityDistricts;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 404 на /districts/<slug>/ (або /ru/districts/<slug>/) хоста одного міста, а район із таким slug є в іншому
 * активному місті того ж сайту → 301 на той самий шлях на хості того міста (логіка — CrossCityDistricts).
 * Послуги не потребують: їхні адреси однакові на всіх хостах сайту.
 */
final class CrossCityDistrictSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CrossCityDistricts $crossCity)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // пріоритет вищий за ErrorListener (-128): він лише рендерить 404
        return [KernelEvents::EXCEPTION => ['onKernelException', 10]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return;
        }
        $city = $request->attributes->get(ServiceHostSubscriber::ATTR_CITY);
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        if (!$city instanceof ServiceCity || !$site instanceof ServiceSite) {
            return;
        }
        $target = $this->crossCity->target($request, $site, $city, $request->getPathInfo());
        if ($target === null) {
            return;
        }
        $query = $request->getQueryString();
        $event->setResponse(new RedirectResponse($target . ($query !== null && $query !== '' ? '?' . $query : ''), 301));
    }
}
