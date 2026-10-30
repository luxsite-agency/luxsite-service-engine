<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Api\Admin;

use App\AdminApi\ApiProblem;
use App\AdminApi\ContentService;
use App\AdminApi\ImageInfo;
use App\AdminApi\ImageService;
use App\AdminApi\LiveThemePolicy;
use App\AdminApi\RedirectService;
use App\AdminApi\ThemeService;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MCP-сервер для AI-агентів (Streamable HTTP, JSON-RPC 2.0, без сесій і SSE — кожен запит самодостатній):
 *   у MCP-клієнті: сервер Streamable HTTP https://<ADMIN_HOST>/mcp, заголовок Authorization: Bearer <svc_…>
 * Той самий firewall admin_api і ті самі дозволи, що в REST; інструменти — тонка обгортка ContentService.
 * Методи: initialize, notifications/initialized, ping, tools/list, tools/call. GET — 405 (потоку подій нема).
 */
final class McpController extends AbstractController
{
    private const string PROTOCOL = '2025-06-18';

    public function __construct(private readonly ContentService $content, private readonly ThemeService $theme, private readonly RedirectService $redirects, private readonly ImageService $images)
    {
    }

    #[Route('/mcp', name: 'api_admin_mcp', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $msg = json_decode($request->getContent(), true);
        if (!\is_array($msg) || ($msg['jsonrpc'] ?? '') !== '2.0' || !\is_string($msg['method'] ?? null)) {
            return $this->rpcError(null, -32600, 'Очікується JSON-RPC 2.0 запит', 400);
        }
        $id = $msg['id'] ?? null;
        $params = \is_array($msg['params'] ?? null) ? $msg['params'] : [];
        if (str_starts_with($msg['method'], 'notifications/')) {
            return new Response('', Response::HTTP_ACCEPTED);
        }

        return match ($msg['method']) {
            'initialize' => $this->rpcResult($id, [
                'protocolVersion' => \is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'service-engine', 'version' => '1'],
                'instructions' => 'Контент і дизайн сервісних сайтів LuxSite: спершу me (сайти, міста, дозволи), далі list/get, зміни — update (лише дозволені поля; відповідь містить перелік). Фото послуг, категорій, постів, робіт, команди й відгуків: image_put (url або data base64; відповідь — url, розміри) → image_delete; get показує блок image. Дизайн: theme_list → theme_get (поточний текст шаблону рушія/паку/живий) → theme_put (перевірка Twig і рендер сторінок; провал = файл повертається) → theme_delete/theme_revert. Шаблони рушія доступні як @engine/… для extends; лого й перемикач мов UA/RU зберігати. Тексти без вигаданих фактів; після правок на проді — перевірити живу сторінку.',
            ]),
            'ping' => $this->rpcResult($id, new \stdClass()),
            'tools/list' => $this->rpcResult($id, ['tools' => $this->tools()]),
            'tools/call' => $this->call($id, $params),
            default => $this->rpcError($id, -32601, 'Невідомий метод: ' . $msg['method']),
        };
    }

    #[Route('/mcp', name: 'api_admin_mcp_get', methods: ['GET', 'DELETE'])]
    public function other(): Response
    {
        return new JsonResponse(['error' => 'method_not_allowed', 'error_description' => 'MCP без потоку подій: лише POST з JSON-RPC'], Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST']);
    }

    /** @return list<array<string, mixed>> */
    private function tools(): array
    {
        $kinds = explode('|', ContentService::KINDS);
        $kindProp = ['type' => 'string', 'enum' => $kinds, 'description' => 'Вид запису: items (послуги), categories, districts (райони міста), posts (блог), prices (прайс по містах), item-texts / category-texts (тексти послуги/категорії під місто), works (виконані роботи), team (команда), reviews (відгуки)'];
        $imageKindProp = ['type' => 'string', 'enum' => explode('|', ImageInfo::KINDS), 'description' => 'Вид запису з фото: items (послуги), categories, posts (блог), works (роботи), team (команда), reviews (відгуки)'];
        $siteProp = ['type' => 'string', 'description' => 'Slug сайту з me (наприклад example)'];

        return [
            ['name' => 'me', 'description' => 'Хто я: користувач, токен і дозволи, доступні сайти з містами (id, slug, host) і адреса приймача content-studio.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false]],
            ['name' => 'list', 'description' => 'Список записів виду kind на сайті site (короткі поля: id, slug, назва, активність). Фільтри: q (підрядок назви/слага), city (slug міста — districts, prices, тексти), category (id — items), item (id — prices).',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'kind'], 'properties' => ['site' => $siteProp, 'kind' => $kindProp, 'q' => ['type' => 'string'], 'city' => ['type' => 'string'], 'category' => ['type' => 'integer'], 'item' => ['type' => 'integer']]]],
            ['name' => 'get', 'description' => 'Один запис з усіма дозволеними для правки полями (тексти uk/ru, SEO, FAQ як список {q,a}, активність).',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'kind', 'id'], 'properties' => ['site' => $siteProp, 'kind' => $kindProp, 'id' => ['type' => 'integer']]]],
            ['name' => 'update', 'description' => 'Змінити поля запису (лише дозволені — перелік у помилці 422 чи в get). fields — об’єкт {поле: нове значення}; FAQ — список {q, a}; булеві — true/false. Зміни одразу на сайті. Прайс (kind=prices) потребує дозволу prices:write.',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'kind', 'id', 'fields'], 'properties' => ['site' => $siteProp, 'kind' => $kindProp, 'id' => ['type' => 'integer'], 'fields' => ['type' => 'object', 'additionalProperties' => true]]]],
            ['name' => 'theme_list', 'description' => 'Дизайн: які шаблони рушія можна перекрити (services/**, partials/**) і звідки зараз береться кожен (engine | pack | live), theme.css, кількість версій в історії. Потрібен дозвіл theme:write.',
                'inputSchema' => ['type' => 'object', 'required' => ['site'], 'properties' => ['site' => $siteProp]]],
            ['name' => 'theme_get', 'description' => 'Дизайн: поточний текст файлу (живий, з паку або рушія) і список версій в історії. path — як у theme_list (services/home.html.twig, partials/_img.html.twig, theme.css).',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'path'], 'properties' => ['site' => $siteProp, 'path' => ['type' => 'string']]]],
            ['name' => 'theme_put', 'description' => 'Дизайн: записати живий файл (шаблон або theme.css). Twig перевіряється на синтаксис, після запису рендеряться головна, каталог, райони, FAQ, блог, перша послуга й район — якщо 5xx чи обірваний HTML, файл повертається й повертається помилка. Попередня версія — в історії. Радше extends @engine/… з перевизначенням блоків, ніж повна копія. ' . LiveThemePolicy::summary() . ' Зайнято іншим записом — помилка 409, повторити за кілька секунд.',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'path', 'content'], 'properties' => ['site' => $siteProp, 'path' => ['type' => 'string'], 'content' => ['type' => 'string']]]],
            ['name' => 'theme_delete', 'description' => 'Дизайн: прибрати живе перекриття — далі береться файл паку або рушія. Версія лишається в історії.',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'path'], 'properties' => ['site' => $siteProp, 'path' => ['type' => 'string']]]],
            ['name' => 'theme_revert', 'description' => 'Дизайн: повернути версію з історії (version з theme_get, формат YYYYMMDD-HHMMSS) — через ту саму перевірку, що theme_put.',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'path', 'version'], 'properties' => ['site' => $siteProp, 'path' => ['type' => 'string'], 'version' => ['type' => 'string']]]],
            ['name' => 'redirects_list', 'description' => 'SEO: правила редиректів сайту (id, source, target, code 301/302/404/410, kind manual|auto|pack, city — slug міста або null = усі міста, hits). Фільтри: city, code, kind, q (підрядок адреси). Потрібен content:read.',
                'inputSchema' => ['type' => 'object', 'required' => ['site'], 'properties' => ['site' => $siteProp, 'city' => ['type' => 'string'], 'code' => ['type' => 'integer'], 'kind' => ['type' => 'string', 'enum' => ['manual', 'auto', 'pack']], 'q' => ['type' => 'string']]]],
            ['name' => 'redirect_create', 'description' => 'SEO: створити або замінити правило редиректу: source — стара адреса (шлях від кореня або повний URL старої сторінки), code 301 (типово) | 302 | 404 | 410, target — нова адреса для 301/302 (шлях /… або URL; чужий домен — лише адміністратору), city — slug міста, якщо правило лише для його хоста (порожньо = усі міста). Правило спрацьовує лише на 404: жива адреса, цикл чи редирект на себе — помилка 422 з поясненням. Ціль, що сама переадресовується, замінюється кінцевою. Потрібен content:write.',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'source'], 'properties' => ['site' => $siteProp, 'source' => ['type' => 'string'], 'code' => ['type' => 'integer', 'enum' => [301, 302, 404, 410]], 'target' => ['type' => 'string'], 'city' => ['type' => 'string']]]],
            ['name' => 'redirect_delete', 'description' => 'SEO: видалити правило редиректу за id (з redirects_list). Потрібен content:write.',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'id'], 'properties' => ['site' => $siteProp, 'id' => ['type' => 'integer']]]],
            ['name' => 'not_found_list', 'description' => 'SEO: журнал 404 сайту — адреси, що віддали 404 на хостах міст і не мають правила (path, city, host, hits, last_hit_at, referer без параметрів), найчастіші першими. Фільтри: city (slug), q (підрядок), limit (до 1000). Для звірки з GSC перед масовим створенням правил. Потрібен content:read.',
                'inputSchema' => ['type' => 'object', 'required' => ['site'], 'properties' => ['site' => $siteProp, 'city' => ['type' => 'string'], 'q' => ['type' => 'string'], 'limit' => ['type' => 'integer']]]],
            ['name' => 'image_put', 'description' => 'Фото запису (послуга, категорія, пост, робота, член команди, відгук): завантажити за url (лише публічні http(s)-адреси; приватні й локальні відхиляються) або з data (base64 вмісту файлу, filename для підказки імені). JPEG/PNG/WebP за вмістом, до 10 МБ і 8000 px; SVG/GIF/HEIC — помилка. alt (uk) і alt_ru (лише posts) — підписи для img alt. Старий файл видаляється, кеш розмірів перебудовується сам. Відповідь: image {url, name, alt, alt_ru, width, height, bytes}. Ліміт — 30 завантажень на хвилину на токен. Потрібен дозвіл content:write.',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'kind', 'id'], 'properties' => ['site' => $siteProp, 'kind' => $imageKindProp, 'id' => ['type' => 'integer'], 'url' => ['type' => 'string', 'description' => 'https://… публічне зображення'], 'data' => ['type' => 'string', 'description' => 'base64 вмісту файлу (замість url)'], 'filename' => ['type' => 'string', 'description' => 'ім’я файлу для data, напр. kitchen.jpg (у шлях не потрапляє)'], 'alt' => ['type' => 'string'], 'alt_ru' => ['type' => 'string']]]],
            ['name' => 'image_delete', 'description' => 'Прибрати фото запису: файл видаляється, alt очищається; сторінка показує заглушку/без фото. Потрібен дозвіл content:write.',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'kind', 'id'], 'properties' => ['site' => $siteProp, 'kind' => $imageKindProp, 'id' => ['type' => 'integer']]]],
            ['name' => 'upsert_city_texts', 'description' => 'Створити або оновити тексти послуги (kind=item-texts, item_id) або категорії (kind=category-texts, category_id) для міста city_id: порожнє поле падає на базовий текст. Проти дублів між містами.',
                'inputSchema' => ['type' => 'object', 'required' => ['site', 'kind', 'city_id', 'fields'], 'properties' => ['site' => $siteProp, 'kind' => ['type' => 'string', 'enum' => ['item-texts', 'category-texts']], 'item_id' => ['type' => 'integer'], 'category_id' => ['type' => 'integer'], 'city_id' => ['type' => 'integer'], 'fields' => ['type' => 'object', 'additionalProperties' => true]]]],
        ];
    }

    /** @param array<string, mixed> $params */
    private function call(mixed $id, array $params): JsonResponse
    {
        $name = (string) ($params['name'] ?? '');
        $a = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        try {
            $result = match ($name) {
                'me' => $this->content->me($this->getUser() instanceof User ? $this->getUser() : null),
                'list' => $this->content->list((string) ($a['site'] ?? ''), (string) ($a['kind'] ?? ''), array_intersect_key($a, array_flip(['q', 'city', 'category', 'item']))),
                'get' => $this->content->get((string) ($a['site'] ?? ''), (string) ($a['kind'] ?? ''), (int) ($a['id'] ?? 0)),
                'update' => $this->content->patch((string) ($a['site'] ?? ''), (string) ($a['kind'] ?? ''), (int) ($a['id'] ?? 0), \is_array($a['fields'] ?? null) ? $a['fields'] : []),
                'upsert_city_texts' => $this->content->upsertTexts((string) ($a['site'] ?? ''), (string) ($a['kind'] ?? ''), (\is_array($a['fields'] ?? null) ? $a['fields'] : []) + array_intersect_key($a, array_flip(['item_id', 'category_id', 'city_id'])))[0],
                'image_put' => $this->imagePut($a),
                'image_delete' => $this->images->delete((string) ($a['site'] ?? ''), (string) ($a['kind'] ?? ''), (int) ($a['id'] ?? 0)),
                'theme_list' => $this->theme->list((string) ($a['site'] ?? '')),
                'theme_get' => $this->theme->get((string) ($a['site'] ?? ''), (string) ($a['path'] ?? '')),
                'theme_put' => $this->theme->put((string) ($a['site'] ?? ''), (string) ($a['path'] ?? ''), (string) ($a['content'] ?? '')),
                'theme_delete' => $this->theme->delete((string) ($a['site'] ?? ''), (string) ($a['path'] ?? '')),
                'theme_revert' => $this->theme->revert((string) ($a['site'] ?? ''), (string) ($a['path'] ?? ''), (string) ($a['version'] ?? '')),
                'redirects_list' => $this->redirects->list((string) ($a['site'] ?? ''), array_intersect_key($a, array_flip(['city', 'code', 'kind', 'q']))),
                'redirect_create' => $this->redirects->create((string) ($a['site'] ?? ''), array_intersect_key($a, array_flip(['source', 'code', 'target', 'city'])))[0],
                'redirect_delete' => (function () use ($a): array {
                    $this->redirects->delete((string) ($a['site'] ?? ''), (int) ($a['id'] ?? 0));

                    return ['deleted' => (int) ($a['id'] ?? 0)];
                })(),
                'not_found_list' => $this->redirects->notFound((string) ($a['site'] ?? ''), array_intersect_key($a, array_flip(['city', 'q', 'limit']))),
                default => throw new ApiProblem('Невідомий інструмент: ' . $name, 404),
            };
        } catch (ApiProblem $e) {
            // 200 = «нічого не змінено»: не помилка, а відповідь; решта — isError для моделі
            return $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => $e->status !== 200]);
        }

        return $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)]], 'structuredContent' => $result]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function imagePut(array $a): array
    {
        $site = (string) ($a['site'] ?? '');
        $kind = (string) ($a['kind'] ?? '');
        $id = (int) ($a['id'] ?? 0);
        $alt = isset($a['alt']) && \is_scalar($a['alt']) ? (string) $a['alt'] : null;
        $altRu = isset($a['alt_ru']) && \is_scalar($a['alt_ru']) ? (string) $a['alt_ru'] : null;
        if (!empty($a['url']) && \is_string($a['url'])) {
            return $this->images->putUrl($site, $kind, $id, $a['url'], $alt, $altRu);
        }
        if (!empty($a['data']) && \is_string($a['data'])) {
            return $this->images->putBase64($site, $kind, $id, $a['data'], isset($a['filename']) && \is_scalar($a['filename']) ? (string) $a['filename'] : null, $alt, $altRu);
        }
        throw new ApiProblem('image_put: потрібне url або data (base64)', 400);
    }

    private function rpcResult(mixed $id, mixed $result): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function rpcError(mixed $id, int $code, string $message, int $status = 200): JsonResponse
    {
        return new JsonResponse(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
