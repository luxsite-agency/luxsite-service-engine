<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Російська версія сайту без перекладу (app.ru_mode). Діє лише на сторінках сайту (хост міста, маршрут з _locale=ru):
 *  - off — 404 одразу після роутингу (legacy-301 і 410 паку спрацьовують раніше, тож старі /ru/-адреси ведуть куди треба);
 *  - noindex — відповідь /ru/ отримує X-Robots-Tag «noindex, follow» (meta і hreflang — base.html.twig за ru_mode).
 * full (типово) — нічого не робить.
 */
final class RuModeSubscriber implements EventSubscriberInterface
{
    public const array MODES = ['full', 'noindex', 'off'];

    public function __construct(#[Autowire(param: 'app.ru_mode')] private readonly string $mode)
    {
        if (!\in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf('app.ru_mode: очікується full|noindex|off, отримано «%s».', $mode));
        }
    }

    public static function getSubscribedEvents(): array
    {
        // після RouterListener (32): _locale уже в атрибутах
        return [KernelEvents::REQUEST => ['onRequest', 16], KernelEvents::RESPONSE => ['onResponse', 0]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($this->mode === 'off' && $event->isMainRequest() && $this->isRuSitePage($event->getRequest())) {
            throw new NotFoundHttpException('Російська версія сайту вимкнена (app.ru_mode: off).');
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($this->mode === 'noindex' && $event->isMainRequest() && $this->isRuSitePage($event->getRequest())) {
            $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, follow');
        }
    }

    private function isRuSitePage(Request $request): bool
    {
        return $request->attributes->has(ServiceHostSubscriber::ATTR_CITY) && $request->attributes->get('_locale') === 'ru'
            && $request->attributes->has('_route');
    }
}
