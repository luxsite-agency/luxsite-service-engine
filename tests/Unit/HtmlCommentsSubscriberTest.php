<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\HtmlCommentsSubscriber;
use App\EventSubscriber\ServiceHostSubscriber;
use App\ServiceSite\Html\CommentStripper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Де чистка працює (хости сайтів, HTML), а де ні (адмінка, API, MCP, JSON, редиректи, вимкнено паком). */
final class HtmlCommentsSubscriberTest extends TestCase
{
    private const HTML = "<p>a</p><!-- c -->\n<script>// x\nvar a = 1;</script>";

    private function request(string $url, bool $site = true): Request
    {
        $request = Request::create($url);
        if ($site) {
            $request->attributes->set(ServiceHostSubscriber::ATTR_SITE, new \stdClass());
        }

        return $request;
    }

    private function html(int $status = 200): Response
    {
        return new Response(self::HTML, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function dispatch(Request $request, Response $response, bool $enabled = true, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $subscriber = new HtmlCommentsSubscriber(new CommentStripper(), ['strip_comments' => $enabled]);
        $subscriber->onResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, $response));

        return $response;
    }

    public function testSitePageCleaned(): void
    {
        $out = (string) $this->dispatch($this->request('https://city.example/services/x/'), $this->html())->getContent();
        self::assertSame("<p>a</p>\n<script>var a = 1;</script>", $out);
        self::assertSame(-20, HtmlCommentsSubscriber::getSubscribedEvents()['kernel.response'][1], 'після CityWordsSubscriber (-10)');
    }

    public function testErrorPagesCleanedToo(): void
    {
        self::assertStringNotContainsString('<!--', (string) $this->dispatch($this->request('https://city.example/nope/'), $this->html(404))->getContent());
        self::assertStringNotContainsString('<!--', (string) $this->dispatch($this->request('https://city.example/gone/'), $this->html(410))->getContent());
    }

    public function testUntouchedCases(): void
    {
        $cases = [
            'хост не сайту (адмінка)' => [$this->request('https://admin.example/admin', false), $this->html()],
            '/api/' => [$this->request('https://city.example/api/studio/x'), $this->html()],
            '/mcp' => [$this->request('https://city.example/mcp'), $this->html()],
            '/admin на хості сайту' => [$this->request('https://city.example/admin/x'), $this->html()],
            'редирект' => [$this->request('https://city.example/old'), new RedirectResponse('/new/', 301)],
            'JSON' => [$this->request('https://city.example/x'), new JsonResponse(['html' => self::HTML])],
            'не HTML' => [$this->request('https://city.example/sitemap.xml'), new Response(self::HTML, 200, ['Content-Type' => 'application/xml'])],
            'потоковий' => [$this->request('https://city.example/x'), new StreamedResponse(static fn () => null, 200, ['Content-Type' => 'text/html'])],
        ];
        foreach ($cases as $name => [$request, $response]) {
            $before = $response->getContent();
            self::assertSame($before, $this->dispatch($request, $response)->getContent(), $name);
        }
        self::assertSame(self::HTML, $this->dispatch($this->request('https://city.example/'), $this->html(), false)->getContent(), 'app.html.strip_comments: false');
        self::assertSame(self::HTML, $this->dispatch($this->request('https://city.example/'), $this->html(), true, HttpKernelInterface::SUB_REQUEST)->getContent(), 'підзапит');
    }
}
