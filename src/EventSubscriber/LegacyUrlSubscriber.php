<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\ServiceSite\CrossCityDistricts;
use App\ServiceSite\LegacyUrls;
use App\ServiceSite\ServiceHostResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Гігієна старих адрес на хостах сервісних сайтів — до роутингу (пріоритет 33: після
 * ServiceHostSubscriber 34, перед RouterListener 32), щоб правило спрацьовувало незалежно від
 * того, чи збігся шлях із якимось маршрутом (/services/<старий-слаг>/ ловив би sv_category → 404).
 * Порядок: 410 для прибраних розділів → /index.php[/шлях] на чистий шлях → 301 з мапи паку → замикаючий слеш і зайвий
 * query одним 301 (/services?task=no → /services/ за один крок; на першому Joomla-сайті таких було 6 074 покази в GSC).
 * Лише GET/HEAD; POST (форми, API) не чіпаємо. Query при 301 зберігається.
 */
final class LegacyUrlSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LegacyUrls $legacy,
        private readonly CrossCityDistricts $crossCity,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 33]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->attributes->has(ServiceHostSubscriber::ATTR_CITY)) {
            return;
        }
        $path = $request->getPathInfo();

        if ($this->legacy->isGone($path)) {
            $event->setResponse(new Response(
                '<!doctype html><html lang="uk"><head><meta charset="utf-8"><title>410 Gone</title></head>'
                . '<body><h1>410 Gone</h1><p>Цієї сторінки більше немає.</p></body></html>',
                Response::HTTP_GONE,
                ['Content-Type' => 'text/html; charset=UTF-8', 'X-Robots-Tag' => 'noindex'],
            ));

            return;
        }
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return;
        }

        $query = $request->getQueryString();
        $suffix = $query !== null && $query !== '' ? '?' . $query : '';

        // /index.php і /index.php/<шлях> (non-SEF Joomla; Symfony бере їх як base URL і генерує canonical з index.php —
        // повний дубль сайту після Joomla-імпорту) → 301 на чистий шлях одним кроком (слеш + query за тими ж правилами)
        if (str_ends_with($request->getBaseUrl(), '/index.php')) {
            $target = $this->legacy->trailingSlashTarget($path) ?? $path;
            $clean = $this->legacy->cleanQueryTarget($request, $target);
            $event->setResponse(new RedirectResponse($clean ?? $request->getSchemeAndHttpHost() . $target . $suffix, 301));

            return;
        }

        $to = $this->legacy->legacyTarget($request, $path);
        if ($to !== null) {
            // Мапа app.legacy_redirects змінна: адреса зі старого сайту може стати справжньою сторінкою
            // (14.10.2026 так сталося з /services/ → сторінка «Усі послуги»). 301 без заголовків кешу
            // браузер тримає назавжди і нової відповіді вже не питає, тому саме ці редіректи не кешуємо.
            // Структурні 301 нижче (index.php, слеш, чистий query) лишаються кешованими — вони не міняються.
            // query їде далі за правилами цільової сторінки (трекінг лишається, ?Itemid= зрізається) — один 301
            if (!str_contains($to, '?')) {
                $to .= $this->legacy->queryFor($request, (string) parse_url($to, PHP_URL_PATH));
            }
            $response = new RedirectResponse($to, 301);
            $response->headers->set('Cache-Control', 'no-store, max-age=0');
            $event->setResponse($response);

            return;
        }
        $slashed = $this->legacy->trailingSlashTarget($path);
        if ($slashed !== null) {
            // одразу й чистий query для нового шляху, щоб не було ланцюжка 301 → 301
            $clean = $this->legacy->cleanQueryTarget($request, $slashed);
            // район без слеша, якого нема в цьому місті, але є в іншому → одразу на його хост (десятки адрес на першому Joomla-сайті)
            $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
            $city = $request->attributes->get(ServiceHostSubscriber::ATTR_CITY);
            $cross = $site instanceof ServiceSite && $city instanceof ServiceCity ? $this->crossCity->target($request, $site, $city, $slashed) : null;
            if ($cross !== null) {
                $q = $clean !== null ? (string) parse_url($clean, PHP_URL_QUERY) : ltrim($suffix, '?');
                $event->setResponse(new RedirectResponse($cross . ($q !== '' ? '?' . $q : ''), 301));

                return;
            }
            $event->setResponse(new RedirectResponse($clean ?? $request->getSchemeAndHttpHost() . $slashed . $suffix, 301));

            return;
        }
        $clean = $this->legacy->cleanQueryTarget($request);
        if ($clean !== null) {
            $event->setResponse(new RedirectResponse($clean, 301));
        }
    }
}
