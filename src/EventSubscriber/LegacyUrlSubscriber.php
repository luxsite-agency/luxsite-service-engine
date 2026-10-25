<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\ServiceSite\CrossCityDistricts;
use App\ServiceSite\LegacyUrls;
use App\ServiceSite\Redirect\LiveUrls;
use App\ServiceSite\Redirect\RedirectCode;
use App\ServiceSite\Redirect\RedirectManager;
use App\ServiceSite\Redirect\RedirectPath;
use App\ServiceSite\Redirect\RedirectResponder;
use App\ServiceSite\ServiceHostResolver;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Гігієна старих адрес на хостах сервісних сайтів — до роутингу (пріоритет 33: після
 * ServiceHostSubscriber 35 і SiteLocaleSubscriber 34, перед RouterListener 32), щоб правило спрацьовувало незалежно від
 * того, чи збігся шлях із якимось маршрутом (/services/<старий-слаг>/ ловив би sv_category → 404).
 * Порядок: 410 для прибраних розділів → /index.php[/шлях] на чистий шлях → 301 з мапи паку → замикаючий слеш і зайвий
 * query одним 301 (/services?task=no → /services/ за один крок; на першому Joomla-сайті таких було 6 074 покази в GSC).
 * Лише GET/HEAD; POST (форми, API) не чіпаємо. Query при 301 зберігається.
 * Перед замикаючим слешем — правило редиректу бренду з БД для цієї адреси (зі слешем чи без, RedirectManager::find):
 * один 301 на ціль замість /x → /x/ → ціль, 410 — одразу (25.10.2026; на trysail 57 з 60 зовнішніх беклінків — на старі адреси).
 */
final class LegacyUrlSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ?int $pendingHit = null;

    public function __construct(
        private readonly LegacyUrls $legacy,
        private readonly CrossCityDistricts $crossCity,
        private readonly RedirectManager $redirects,
        private readonly RedirectResponder $responder,
        private readonly LiveUrls $live,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 33], KernelEvents::TERMINATE => 'onTerminate'];
    }

    /** Лічильник спрацювань правила — після відповіді, як у NotFoundRedirectSubscriber */
    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->pendingHit === null) {
            return;
        }
        $id = $this->pendingHit;
        $this->pendingHit = null;
        try {
            $this->redirects->hit($id);
        } catch (DbalException) {
        }
    }

    public function reset(): void
    {
        $this->pendingHit = null;
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
            // правило бренду для старої адреси — одним кроком на ціль (без проміжного слеша); правила нема — слеш, як було
            if ($this->ruleResponse($event, $path, $slashed)) {
                return;
            }
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

    /**
     * Правило з БД (місто, далі сайт) для адреси зі слешем чи без: 301/302 — відповідь на ціль, 410 — GoneHttpException
     * (сторінка «видалено»), 404 — без слеш-редиректу (далі звичайна 404 правила). Правила діють лише на неживі адреси: якщо
     * адреса зі слешем відкривається (правило застаріло), правило не застосовується — слеш, як було. true — правило спрацювало.
     */
    private function ruleResponse(RequestEvent $event, string $path, string $slashed): bool
    {
        $request = $event->getRequest();
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        $city = $request->attributes->get(ServiceHostSubscriber::ATTR_CITY);
        // шукаємо за формою зі слешем (як на 404 після слеш-редиректу): точний збіг у ORDER BY — зі слешем, тож правило міста
        // «/x/» і далі перемагає правило сайту «/x»; у isLive — та сама нормалізована форма (без повторного кодування)
        $normalized = RedirectPath::fromRequest($slashed);
        if (!$site instanceof ServiceSite || !$city instanceof ServiceCity || $normalized === null) {
            return false;
        }
        try {
            $rule = $this->redirects->find((int) $site->getId(), (int) $city->getId(), $normalized);
        } catch (DbalException) {
            return false;
        }
        if ($rule === null || $this->live->isLive($site, $city, $normalized)) {
            return false;
        }
        if ($rule['code']->isRedirect() && $rule['target'] !== null) {
            $this->pendingHit = $rule['id'];
            $event->setResponse($this->responder->redirect($request, $rule['target'], $rule['code']));
        } elseif (RedirectCode::Gone === $rule['code']) {
            $this->pendingHit = $rule['id'];
            throw new GoneHttpException('Сторінку видалено');
        } else {
            // правило 404 (чи 301 без цілі): одразу 404 без слеш-301 роутера; лічильник і журнал — NotFoundRedirectSubscriber,
            // який знайде те саме правило на 404 (тут hit не ставимо, інакше рахувалось би двічі)
            throw new NotFoundHttpException('Сторінку не знайдено (правило редиректу).');
        }

        return true;
    }
}
