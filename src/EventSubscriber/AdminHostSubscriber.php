<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use App\AdminApi\ApiEndpoints;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Адмінка живе ТІЛЬКИ на службовому домені (ADMIN_HOST, напр. admin.example.com).
 * На міських доменах /admin і /login не існують (404) — менша поверхня атак
 * і жодних слідів адмінки в індексації міст.
 *
 * API (23.10.2026): агент клієнта стукав на /api/admin і /mcp хоста сайту, отримував голий 404 і робив висновок «API не
 * працює». Тепер для машин — JSON 404 `wrong_host` з правильною адресою: /api/admin/* і /mcp на хості сайту → хост адмінки
 * (публічний і так); /api/studio/* на хості адмінки → домени міст сайту цієї адмінки (лише його, не всього мультисайту).
 * Статуси для правильних хостів не змінюються; /admin і /login на хостах сайту — як і раніше голий 404.
 */
final class AdminHostSubscriber implements EventSubscriberInterface
{
    public const string WRONG_HOST = 'wrong_host';
    /** JSON-RPC: діапазон -32000…-32099 — помилки реалізації сервера. */
    public const int RPC_WRONG_HOST = -32001;

    public function __construct(
        #[Autowire(env: 'ADMIN_HOST')]
        private readonly string $adminHost,
        private readonly ApiEndpoints $endpoints,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 40]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $onAdminHost = strtolower($request->getHost()) === strtolower($this->adminHost);
        // /logout НЕ блокуємо: він потрібен і Google-юзерам на міських доменах
        // /api/admin — REST адмінки (персональні токени), теж лише на службовому хості
        $isApiPath = str_starts_with($path, '/api/admin') || $path === '/mcp';
        $isAdminPath = $isApiPath || str_starts_with($path, '/admin') || $path === '/login';

        if ($isAdminPath && !$onAdminHost) {
            if ($isApiPath) {
                $event->setResponse($path === '/mcp' ? $this->mcpOnSiteHost($request) : $this->adminApiOnSiteHost($path));

                return;
            }
            throw new NotFoundHttpException('Not found.');
        }

        if ($onAdminHost && ($path === ApiEndpoints::STUDIO_PATH || str_starts_with($path, ApiEndpoints::STUDIO_PATH . '/'))) {
            $event->setResponse($this->studioOnAdminHost($path));
        }
    }

    private function adminApiOnSiteHost(string $path): JsonResponse
    {
        return self::json([
            'error' => self::WRONG_HOST,
            'error_description' => sprintf('REST адмінки працює лише на хості адмінки %s, не на домені сайту. Повторіть запит на correct_url з тим самим токеном; адреси, дозволи й поля — GET %s.', $this->endpoints->adminHost(), $this->endpoints->meUrl()),
            'correct_url' => 'https://' . $this->endpoints->adminHost() . self::safePath($path),
            'admin_api_url' => $this->endpoints->adminApiUrl(),
            'me_url' => $this->endpoints->meUrl(),
            'mcp_url' => $this->endpoints->mcpUrl(),
            'docs_url' => ApiEndpoints::DOCS_URL,
        ]);
    }

    /** MCP-клієнт чекає JSON-RPC: помилка з тим самим id, адреса — і в message (його показують люди), і в data. */
    private function mcpOnSiteHost(Request $request): JsonResponse
    {
        $msg = json_decode($request->getContent(), true);
        $id = \is_array($msg) && (\is_int($msg['id'] ?? null) || \is_string($msg['id'] ?? null)) ? $msg['id'] : null;

        return self::json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => self::RPC_WRONG_HOST,
                'message' => sprintf('wrong_host: MCP працює лише на %s (хост адмінки), не на домені сайту. Підключіть: %s', $this->endpoints->mcpUrl(), $this->endpoints->claudeMcpCommand('service')),
                'data' => ['error' => self::WRONG_HOST, 'mcp_url' => $this->endpoints->mcpUrl(), 'admin_api_url' => $this->endpoints->adminApiUrl(), 'docs_url' => ApiEndpoints::DOCS_URL],
            ],
        ]);
    }

    private function studioOnAdminHost(string $path): JsonResponse
    {
        $site = $this->endpoints->adminSite();
        $urls = $site === null ? [] : $this->endpoints->studioUrls($site);
        $rest = substr($path, \strlen(ApiEndpoints::STUDIO_PATH));
        $body = [
            'error' => self::WRONG_HOST,
            'error_description' => $urls === []
                ? sprintf('Приймач content-studio %s працює на доменах міст сайту, не на хості адмінки. Адреси для кожного сайту й міста — GET %s (endpoints.sites[].studio).', ApiEndpoints::STUDIO_PATH, $this->endpoints->meUrl())
                : sprintf('Приймач content-studio %s працює на доменах міст сайту, не на хості адмінки. Адреса для студії — studio_api_urls (по одній на місто).', ApiEndpoints::STUDIO_PATH),
        ];
        if ($site !== null) {
            $body['site'] = $site->getSlug();
        }
        if ($urls !== []) {
            $body['correct_url'] = $urls[0]['studio_api_url'] . self::safePath($rest);
        }
        $body['studio_api_urls'] = $urls;
        $body['me_url'] = $this->endpoints->meUrl();
        $body['docs_url'] = ApiEndpoints::DOCS_URL;

        return self::json($body);
    }

    /** Шлях запиту назад у відповідь — лише безпечні символи й обмежена довжина (без query: там бувають токени). */
    private static function safePath(string $path): string
    {
        $path = substr($path, 0, 512);

        return preg_match('#^[A-Za-z0-9/_.\-~%]*$#', $path) === 1 ? $path : '';
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body): JsonResponse
    {
        $response = new JsonResponse($body, Response::HTTP_NOT_FOUND, ['X-Robots-Tag' => 'noindex', 'Cache-Control' => 'no-store']);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $response;
    }
}
