<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\ServiceSite\Html\CommentStripper;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Чистий HTML на хостах сайтів (вимога власника 25.10.2026, стандарт рушія): з відповіді прибираються коментарі
 * розробника — HTML, CSS у <style>, JS в інлайн-скриптах (App\ServiceSite\Html\CommentStripper). Пріоритет -20: після
 * CityWordsSubscriber (-10), який до того споживає маркери <!--city-raw-->, тому й вони з виводу зникають.
 *
 * Лише сторінки сайтів (request attribute service_site — адмін-хост його не має), не /api, не /mcp, не /admin; лише text/html,
 * без редиректів. Вимкнути в паку: app.html: { strip_comments: false } (docs/site-pack.md «Чистий HTML»).
 */
final class HtmlCommentsSubscriber implements EventSubscriberInterface
{
    /**
     * @param array{strip_comments?: bool} $html
     */
    public function __construct(
        private readonly CommentStripper $stripper,
        #[Autowire('%app.html%')]
        private readonly array $html,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -20]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->supports($event->getRequest(), $event->getResponse())) {
            return;
        }
        $response = $event->getResponse();
        $content = $response->getContent();
        if (!\is_string($content) || $content === '') {
            return;
        }
        $clean = $this->stripper->html($content);
        if ($clean !== $content) {
            $response->setContent($clean);
        }
    }

    /** Чи відповідь підлягає чистці: увімкнено, хост сайту, не службовий шлях, HTML без редиректу. */
    public function supports(Request $request, Response $response): bool
    {
        if (!($this->html['strip_comments'] ?? true)) {
            return false;
        }
        if (!$request->attributes->has(ServiceHostSubscriber::ATTR_SITE)) {
            return false;
        }
        $path = $request->getPathInfo();
        if (str_starts_with($path, '/api/') || $path === '/api' || $path === '/mcp' || str_starts_with($path, '/mcp/') || str_starts_with($path, '/admin')) {
            return false;
        }
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }
        $status = $response->getStatusCode();
        if ($status < 200 || ($status >= 300 && $status < 400)) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type', ''), 'text/html');
    }
}
