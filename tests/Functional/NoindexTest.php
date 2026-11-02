<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * noindex послуги й категорії (02.11.2026) на типовому ядрі (flat, app.ru_mode: full): без позначки — як було (без
 * X-Robots-Tag, у sitemap, hreflang є); з позначкою через REST — «noindex, follow» у meta й заголовку, поза sitemap.
 * Сторінки app.pages у REST (kind=pages) — поля читаються й правляться, слаг — ні.
 */
final class NoindexTest extends SeoTestCase
{
    public function testItemNoindexViaRest(): void
    {
        $url = 'https://' . $this->apex . '/services/item-' . $this->s . '/';
        $before = $this->http('GET', $url);
        self::assertSame(200, $before->getStatusCode());
        // у debug-ядрі Symfony сам ставить «X-Robots-Tag: noindex» (framework.disallow_search_engine_index), у prod — ні
        self::assertNotSame('noindex, follow', $before->headers->get('X-Robots-Tag'), 'без позначки — без нашого заголовка');
        self::assertStringNotContainsString('noindex', (string) $before->getContent());
        self::assertStringContainsString('hreflang="ru-UA"', (string) $before->getContent(), 'ru_mode full — hreflang як був');
        self::assertStringContainsString('/services/item-' . $this->s . '/</loc>', $this->sitemap());

        $token = $this->token([ApiScope::ContentRead, ApiScope::ContentWrite]);
        $r = $this->api($token, 'PATCH', 'items/' . $this->itemId, ['noindex' => true]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        self::assertTrue(json_decode((string) $this->api($token, 'GET', 'items/' . $this->itemId)->getContent(), true)['noindex']);

        $after = $this->http('GET', $url);
        self::assertSame(200, $after->getStatusCode(), 'сторінка жива');
        self::assertSame('noindex, follow', $after->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', (string) $after->getContent());
        self::assertStringNotContainsString('/services/item-' . $this->s . '/</loc>', $this->sitemap(), 'поза sitemap');
        self::assertStringContainsString('/services/hidden-' . $this->s . '/</loc>', $this->sitemap(), 'інші послуги на місці');
    }

    public function testCategoryNoindexField(): void
    {
        $token = $this->token([ApiScope::ContentRead, ApiScope::ContentWrite]);
        $row = json_decode((string) $this->api($token, 'GET', 'categories/' . $this->categoryId)->getContent(), true);
        self::assertFalse($row['noindex']);
        self::assertSame(200, $this->api($token, 'PATCH', 'categories/' . $this->categoryId, ['noindex' => true])->getStatusCode());
        self::assertTrue(json_decode((string) $this->api($token, 'GET', 'categories/' . $this->categoryId)->getContent(), true)['noindex']);
    }

    public function testPagesKindInRest(): void
    {
        $em = $this->em();
        $page = (new \App\Entity\ServicePage())->setSite($this->site())->setSlugUk('page-' . $this->s)->setTitleUk('Сторінка');
        $em->persist($page);
        $em->flush();
        $id = (int) $page->getId();
        $em->clear();

        $token = $this->token([ApiScope::ContentRead, ApiScope::ContentWrite]);
        $list = json_decode((string) $this->api($token, 'GET', 'pages')->getContent(), true);
        self::assertSame(1, $list['count']);
        self::assertSame('/page-' . $this->s . '/', $list['items'][0]['path']);
        self::assertSame('page', $list['items'][0]['template']);

        $r = $this->api($token, 'PATCH', 'pages/' . $id, ['h1_uk' => 'Довгий H1', 'template' => 'hub', 'noindex' => true, 'in_sitemap' => false]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        $row = json_decode((string) $this->api($token, 'GET', 'pages/' . $id)->getContent(), true);
        self::assertSame('Довгий H1', $row['h1_uk']);
        self::assertSame('hub', $row['template']);
        self::assertTrue($row['noindex']);
        self::assertFalse($row['in_sitemap']);

        self::assertSame(422, $this->api($token, 'PATCH', 'pages/' . $id, ['template' => 'nope'])->getStatusCode(), 'невідомий шаблон');
        self::assertSame(422, $this->api($token, 'PATCH', 'pages/' . $id, ['slug_uk' => 'x'])->getStatusCode(), 'слаг — лише в адмінці');
    }

    private function sitemap(): string
    {
        return (string) $this->http('GET', 'https://' . $this->apex . '/sitemap.xml')->getContent();
    }

    /** @param list<ApiScope> $scopes */
    private function token(array $scopes): string
    {
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $this->moderatorEmail]);
        self::assertNotNull($user);

        return static::getContainer()->get(AdminApiTokens::class)->issue($user, 'ci noindex', $scopes, 1, $user)->plain;
    }

    /** @param array<string, mixed> $body */
    private function api(string $token, string $method, string $path, array $body = []): Response
    {
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $request = Request::create('https://' . $this->adminHost . '/api/admin/v1/' . $this->slug . '/' . $path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], $body === [] ? '' : (string) json_encode($body));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }
}
