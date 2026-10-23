<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\User;
use App\ServiceSite\Redirect\NotFoundLog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST /api/admin/v1/{site}/redirects і /not-found та MCP-інструменти redirects_list / redirect_create / redirect_delete /
 * not_found_list під персональним токеном: ті самі перевірки, що в адмінці (цикл, жива адреса, чужий домен — лише
 * адміністратору), дозволи content:read / content:write.
 */
final class RedirectApiTest extends SeoTestCase
{
    public function testRestListCreateDeleteWithScopes(): void
    {
        $s = $this->s;
        $write = $this->token($this->moderatorEmail, [ApiScope::ContentRead, ApiScope::ContentWrite]);
        $read = $this->token($this->moderatorEmail, [ApiScope::ContentRead]);

        $r = $this->api($write, 'POST', 'redirects', ['source' => "https://old.example/Old-$s/?x=1", 'target' => "/services/item-$s/"]);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        $row = json_decode((string) $r->getContent(), true);
        self::assertSame("/old-$s/", $row['source']);
        self::assertSame("/services/item-$s/", $row['target']);
        self::assertSame(301, $row['code']);
        self::assertNull($row['city']);
        self::assertSame(200, $this->api($write, 'POST', 'redirects', ['source' => "/old-$s/", 'code' => 302, 'target' => "/services/item-$s/"])->getStatusCode(), 'заміна — 200');

        $r = $this->api($write, 'POST', 'redirects', ['source' => "/gone-$s/", 'code' => 410, 'city' => 'branch']);
        self::assertSame(201, $r->getStatusCode(), (string) $r->getContent());
        self::assertSame('branch', json_decode((string) $r->getContent(), true)['city']);
        self::assertSame(410, $this->http('GET', 'https://' . $this->branch . "/gone-$s/")->getStatusCode());
        self::assertSame(404, $this->http('GET', 'https://' . $this->apex . "/gone-$s/")->getStatusCode(), 'правило міста — лише на його хості');

        self::assertSame(201, $this->api($write, 'POST', 'redirects', ['source' => "/p-$s/", 'target' => "/q-$s/"])->getStatusCode());
        foreach ([
            [['source' => "/services/item-$s/", 'target' => "/x-$s/"], 'відкривається на сайті'],
            [['source' => "/q-$s/", 'target' => "/p-$s/"], 'Цикл'],
            [['source' => "/ext-$s/", 'target' => 'https://evil.example/'], 'адміністратор'],
            [['source' => "/bad-$s/", 'code' => 999], '301, 302, 404, 410'],
            [['source' => "/bad-$s/", 'city' => 'nowhere'], 'Місто не знайдено'],
            [['target' => '/x/'], 'source'],
        ] as [$body, $message]) {
            $r = $this->api($write, 'POST', 'redirects', $body);
            self::assertSame(422, $r->getStatusCode(), json_encode($body) ?: '');
            self::assertStringContainsString($message, (string) (json_decode((string) $r->getContent(), true)['error'] ?? ''));
        }
        self::assertSame(400, $this->api($write, 'POST', 'redirects', [])->getStatusCode(), 'порожнє тіло');

        $r = $this->api($read, 'GET', 'redirects?q=old-' . $s);
        self::assertSame(200, $r->getStatusCode());
        $list = json_decode((string) $r->getContent(), true);
        self::assertSame(1, $list['count']);
        self::assertSame(302, $list['items'][0]['code']);
        self::assertSame(3, json_decode((string) $this->api($read, 'GET', 'redirects')->getContent(), true)['count']);
        self::assertSame(1, json_decode((string) $this->api($read, 'GET', 'redirects?city=branch')->getContent(), true)['count']);
        self::assertSame(1, json_decode((string) $this->api($read, 'GET', 'redirects?code=410')->getContent(), true)['count']);

        self::assertSame(403, $this->api($read, 'POST', 'redirects', ['source' => "/ro-$s/", 'target' => "/x-$s/"])->getStatusCode(), 'без content:write');
        self::assertSame(403, $this->api($read, 'DELETE', 'redirects/' . $list['items'][0]['id'])->getStatusCode());
        self::assertSame(204, $this->api($write, 'DELETE', 'redirects/' . $list['items'][0]['id'])->getStatusCode());
        self::assertSame(404, $this->api($write, 'DELETE', 'redirects/' . $list['items'][0]['id'])->getStatusCode(), 'вже нема');
        self::assertNull($this->target("/old-$s/"));
        self::assertSame(404, $this->api($write, 'GET', 'redirects', [], 'nope-' . $s)->getStatusCode(), 'чужий slug сайту');

        static::getContainer()->get(NotFoundLog::class)->record($this->siteId, $this->apexCityId, "/lost-$s/", 'https://ref.example/page');
        $r = $this->api($read, 'GET', 'not-found?q=lost-' . $s);
        self::assertSame(200, $r->getStatusCode());
        $log = json_decode((string) $r->getContent(), true);
        self::assertSame(1, $log['count']);
        self::assertSame(['path' => "/lost-$s/", 'city' => 'main', 'host' => $this->apex, 'referer' => 'https://ref.example/page'], array_intersect_key($log['items'][0], array_flip(['path', 'city', 'host', 'referer'])));

        $admin = $this->token($this->adminEmail, [ApiScope::ContentRead, ApiScope::ContentWrite]);
        self::assertSame(201, $this->api($admin, 'POST', 'redirects', ['source' => "/ext-$s/", 'target' => 'https://partner.example/'])->getStatusCode(), 'адміністратору чужий домен можна');
    }

    public function testMcpToolsListAndCall(): void
    {
        $s = $this->s;
        $token = $this->token($this->moderatorEmail, [ApiScope::ContentRead, ApiScope::ContentWrite]);
        $tools = $this->mcp($token, 'tools/list')['result']['tools'] ?? [];
        $names = array_column($tools, 'name');
        foreach (['redirects_list', 'redirect_create', 'redirect_delete', 'not_found_list'] as $name) {
            self::assertContains($name, $names);
        }

        $res = $this->mcp($token, 'tools/call', ['name' => 'redirect_create', 'arguments' => ['site' => $this->slug, 'source' => "/mcp-$s/", 'target' => "/services/item-$s/"]]);
        self::assertArrayNotHasKey('isError', $res['result'], json_encode($res) ?: '');
        self::assertSame("/mcp-$s/", $res['result']['structuredContent']['source']);
        $id = (int) $res['result']['structuredContent']['id'];
        self::assertSame("/services/item-$s/", $this->target("/mcp-$s/"));

        $res = $this->mcp($token, 'tools/call', ['name' => 'redirect_create', 'arguments' => ['site' => $this->slug, 'source' => "/services/item-$s/", 'target' => "/mcp-$s/"]]);
        self::assertTrue($res['result']['isError'] ?? false, 'жива адреса — помилка для моделі');
        self::assertStringContainsString('відкривається на сайті', $res['result']['content'][0]['text']);

        $res = $this->mcp($token, 'tools/call', ['name' => 'redirects_list', 'arguments' => ['site' => $this->slug, 'q' => "mcp-$s"]]);
        self::assertSame(1, $res['result']['structuredContent']['count']);

        static::getContainer()->get(NotFoundLog::class)->record($this->siteId, $this->branchCityId, "/lost-mcp-$s/", null);
        $res = $this->mcp($token, 'tools/call', ['name' => 'not_found_list', 'arguments' => ['site' => $this->slug, 'city' => 'branch']]);
        self::assertSame("/lost-mcp-$s/", $res['result']['structuredContent']['items'][0]['path']);

        $res = $this->mcp($token, 'tools/call', ['name' => 'redirect_delete', 'arguments' => ['site' => $this->slug, 'id' => $id]]);
        self::assertSame(['deleted' => $id], $res['result']['structuredContent']);
        self::assertNull($this->target("/mcp-$s/"));
    }

    /** @param list<ApiScope> $scopes */
    private function token(string $email, array $scopes): string
    {
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return static::getContainer()->get(AdminApiTokens::class)->issue($user, 'ci seo', $scopes, 1, $user)->plain;
    }

    /** @param array<string, mixed> $body */
    private function api(string $token, string $method, string $path, array $body = [], ?string $site = null): Response
    {
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $request = Request::create('https://' . $this->adminHost . '/api/admin/v1/' . ($site ?? $this->slug) . '/' . $path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], $body === [] ? '' : (string) json_encode($body));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function mcp(string $token, string $method, array $params = []): array
    {
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $request = Request::create('https://' . $this->adminHost . '/mcp', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params]));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return (array) json_decode((string) $response->getContent(), true);
    }
}
