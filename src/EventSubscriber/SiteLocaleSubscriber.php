<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ServiceSite;
use App\ServiceSite\BrandRoutes;
use App\ServiceSite\SiteLocales;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RequestContext;

/**
 * Мова на хостах брендів (22.10.2026).
 *
 * 1. До роутера (33: після ServiceHostSubscriber 34, перед RouterListener 32): якщо мова розгортання за
 *    замовчуванням (перша з app.locales) не збігається з framework.default_locale (uk — заради адмінки), запит
 *    бренду отримує її як мову. Інакше сторінки без _locale у маршруті (404, /lead, sitemap, robots) і path() без
 *    явної мови пішли б українською — а варіантів маршрутів .uk на такому розгортанні нема. Мова ставиться явно
 *    (setLocale), тож її успадковує й підзапит сторінки помилки. Типове [uk, ru] — нічого не робить.
 *    Без префіксів (app.locale_prefix: false) мова за замовчуванням — перша мова бренду (SiteLocales::default()).
 *    Тут же — розділ статей бренду в параметр _blog контексту роутера (BrandRoutes): path('sv_blog_post', …) у
 *    шаблонах і контролерах дає /<префікс бренду>/… без явного параметра; і _cat_root — рубрики від кореня (BrandRouter).
 * 2. Після роутера (20, перед LocaleListener 16): мова маршруту не з набору бренду (ServiceSite.locales) → 404.
 *    Бренд без власного набору — усі мови розгортання, тобто перевірка нічого не відсікає.
 */
final class SiteLocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SiteLocales $locales,
        private readonly BrandRoutes $brandRoutes,
        #[Autowire(service: 'router.request_context')]
        private readonly RequestContext $routerContext,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $frameworkDefault = 'uk',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['onBeforeRouting', 33], ['onAfterRouting', 20]]];
    }

    public function onBeforeRouting(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        foreach ($this->brandRoutes->contextParams($site instanceof ServiceSite ? $site : null) as $name => $value) {
            $this->routerContext->setParameter($name, $value);
        }
        $default = $this->locales->default();
        if ($default === $this->frameworkDefault || !$request->attributes->get(ServiceHostSubscriber::ATTR_SITE) instanceof ServiceSite) {
            return;
        }
        $request->setDefaultLocale($default);
        $request->setLocale($default);
    }

    public function onAfterRouting(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        $locale = $request->attributes->get('_locale');
        if (!$site instanceof ServiceSite || !\is_string($locale)) {
            return;
        }
        if (!\in_array($locale, $this->locales->enabled($site), true)) {
            throw new NotFoundHttpException(sprintf('Мова «%s» на цьому сайті вимкнена.', $locale));
        }
    }
}
