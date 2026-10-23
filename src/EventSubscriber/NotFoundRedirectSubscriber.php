<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\ServiceSite\LegacyUrls;
use App\ServiceSite\Redirect\LiveUrls;
use App\ServiceSite\Redirect\NotFoundLog;
use App\ServiceSite\Redirect\RedirectCode;
use App\ServiceSite\Redirect\RedirectManager;
use App\ServiceSite\Redirect\RedirectPath;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Менеджер редиректів на сайті (модель shop-engine E3): лише на 404 хоста міста (маршрут не знайдено або контролер не
 * знайшов сутність), GET/HEAD. Правила паку (LegacyUrlSubscriber, до роутингу) лишаються першими — сюди доходить лише те,
 * що вони не перехопили. Порядок на 404:
 *  1) правило з БД (пріоритет 16, перед CrossCityDistrictSubscriber 10): спершу для цього міста, потім для сайту →
 *     301/302 — переадресація (query запиту їде далі за правилами паку, LegacyUrls::queryFor), 410 — сторінка
 *     «видалено» (error410, пак перекриває), 404 — звичайна 404 без запису в журнал;
 *  2) правила нема, але нормалізована адреса інша (регістр, подвійні слеші) і жива — 301 на неї, без журналу;
 *  3) далі хід CrossCityDistrictSubscriber (район в іншому місті → 301);
 *  4) ніхто не відповів (пріоритет 5) — шлях у журнал 404 (без query, IP, UA). Збій БД — звичайна 404.
 * Пріоритети вищі за ErrorListener (-128) і його логування (0): переадресація не пише помилку в лог. Лічильник
 * спрацювань — після відповіді (kernel.terminate): блокування рядка не затримує й не ламає переадресацію.
 * Без правил у БД і без 404 поведінка сайту та сама, що до цього підписника.
 */
final class NotFoundRedirectSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ?int $pendingHit = null;

    public function __construct(
        private readonly RedirectManager $redirects,
        private readonly NotFoundLog $log,
        private readonly LiveUrls $live,
        private readonly LegacyUrls $legacy,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => [['onRule', 16], ['onUnhandled', 5]],
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onRule(ExceptionEvent $event): void
    {
        [$site, $city, $path] = $this->context($event);
        if ($site === null || $city === null || $path === null) {
            return;
        }
        $request = $event->getRequest();
        try {
            $rule = $this->redirects->find((int) $site->getId(), (int) $city->getId(), $path);
            if ($rule === null) {
                if ($path !== rawurldecode($request->getPathInfo()) && $this->live->isLive($site, $city, $path)) {
                    $event->setResponse($this->redirect($request, $path, RedirectCode::Permanent));
                }

                return;
            }
        } catch (DbalException $e) {
            $this->logger->warning('Редиректи недоступні: {message}', ['message' => $e->getMessage()]);

            return;
        }
        $this->pendingHit = $rule['id'];
        if ($rule['code']->isRedirect() && $rule['target'] !== null) {
            $event->setResponse($this->redirect($request, $rule['target'], $rule['code']));
        } elseif (RedirectCode::Gone === $rule['code']) {
            $event->setThrowable(new GoneHttpException('Сторінку видалено', $event->getThrowable()));
        }
    }

    public function onUnhandled(ExceptionEvent $event): void
    {
        [$site, $city, $path] = $this->context($event);
        if ($site === null || $city === null || $path === null || $event->hasResponse() || $this->pendingHit !== null) {
            return;
        }
        try {
            $this->log->record((int) $site->getId(), (int) $city->getId(), $path, $event->getRequest()->headers->get('referer'));
        } catch (DbalException $e) {
            $this->logger->warning('Журнал 404 недоступний: {message}', ['message' => $e->getMessage()]);
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($this->pendingHit === null) {
            return;
        }
        $id = $this->pendingHit;
        $this->pendingHit = null;
        try {
            $this->redirects->hit($id);
        } catch (DbalException $e) {
            $this->logger->info('Лічильник редиректу не оновлено: {message}', ['message' => $e->getMessage()]);
        }
    }

    public function reset(): void
    {
        $this->pendingHit = null;
    }

    /** @return array{0: ServiceSite|null, 1: ServiceCity|null, 2: string|null} */
    private function context(ExceptionEvent $event): array
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$event->getThrowable() instanceof NotFoundHttpException || !\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return [null, null, null];
        }
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        $city = $request->attributes->get(ServiceHostSubscriber::ATTR_CITY);
        if (!$site instanceof ServiceSite || !$city instanceof ServiceCity) {
            return [null, null, null];
        }

        return [$site, $city, RedirectPath::fromRequest($request->getPathInfo())];
    }

    private function redirect(Request $request, string $target, RedirectCode $code): RedirectResponse
    {
        if (RedirectPath::isAbsoluteUrl($target)) {
            $location = $target;
        } else {
            $location = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . RedirectPath::encode($target);
        }
        if (!str_contains($location, '?')) {
            $location .= $this->legacy->queryFor($request, (string) parse_url($location, \PHP_URL_PATH));
        }
        $response = new RedirectResponse($location, $code->value);
        // правила змінні (адмінка): 301 без заголовків кешу браузер тримав би назавжди (див. LegacyUrlSubscriber)
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }
}
