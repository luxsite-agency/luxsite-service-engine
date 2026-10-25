<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\ServiceSite\CalculatorAvailability;
use App\ServiceSite\CalculatorRoutes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Підставляє в RequestContext сегмент типової адреси калькулятора за режимом міста ({_calc} у sv_calculator /
 * sv_calc_lead, див. CalculatorRoutes): path('sv_calculator') дає /calculator-cleaning/ для режиму «за площею» і
 * /calculator/ для «за прайсом». Після ServiceHostSubscriber (34) — потрібні сайт і місто; до RouterListener (32) не
 * обов'язково, але так параметр є вже на момент матчингу. Без калькулятора лишається дефолт маршруту.
 */
final class CalculatorRouteSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly CalculatorAvailability $availability,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 33]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        $city = $request->attributes->get(ServiceHostSubscriber::ATTR_CITY);
        if (!$site instanceof ServiceSite || !$city instanceof ServiceCity) {
            return;
        }
        $mode = $this->availability->modeFor($site, $city);
        $this->router->getContext()->setParameter(
            CalculatorRoutes::PLACEHOLDER,
            $mode === CalculatorAvailability::MODE_PRICELIST ? CalculatorRoutes::SEGMENT_PRICELIST : CalculatorRoutes::SEGMENT_AREA,
        );
    }
}
