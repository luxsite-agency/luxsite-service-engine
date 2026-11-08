<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;
use App\ServiceSite\Turnstile;
use App\Tests\Support\TurnstileKernel;
use App\Tests\Support\TurnstileVerifyMock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloudflare Turnstile увімкнено (TurnstileKernel: site_key паку + секрет у середовищі): віджет у формі й скрипт з defer у
 * base, без коментарів у HTML; заявка без відповіді віджета чи з провальною — спам без листа й конверсії; з успішною —
 * звичайна заявка; siteverify отримує секрет, токен і IP.
 */
final class TurnstileTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return TurnstileKernel::class;
    }

    protected function setUp(): void
    {
        $_SERVER['TURNSTILE_SECRET'] = 'test-turnstile-secret';
        TurnstileVerifyMock::$bodies = [];
        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['TURNSTILE_SECRET']);
        parent::tearDown();
    }

    public function testWidgetAndScriptRendered(): void
    {
        $html = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertStringContainsString('<div class="cf-turnstile" data-sitekey="' . TurnstileKernel::SITE_KEY . '"', $html);
        self::assertSame(1, substr_count($html, 'https://challenges.cloudflare.com/turnstile/v0/api.js'), 'скрипт один раз на сторінку');
        self::assertStringContainsString('turnstile/v0/api.js" defer>', $html);
        self::assertStringNotContainsString('<!--', $html, 'без коментарів у HTML');
    }

    public function testVerificationDecidesLead(): void
    {
        $r = $this->post([]);
        self::assertSame('/thank-you/?src=ok', $r->headers->get('Location'), 'без відповіді віджета — спам без конверсії');
        self::assertSame('turnstile:missing', $this->lastLead()->getSpamReason());
        self::assertSame([], TurnstileVerifyMock::$bodies, 'без токена в Cloudflare не ходимо');
        self::assertEmailCount(0);

        $r = $this->post([Turnstile::FIELD => 'bad-token']);
        self::assertSame('/thank-you/?src=ok', $r->headers->get('Location'));
        self::assertSame('turnstile:failed', $this->lastLead()->getSpamReason());
        self::assertCount(1, TurnstileVerifyMock::$bodies);
        self::assertStringContainsString('secret=test-turnstile-secret', TurnstileVerifyMock::$bodies[0]);
        self::assertStringContainsString('response=bad-token', TurnstileVerifyMock::$bodies[0]);
        self::assertStringContainsString('remoteip=10.', TurnstileVerifyMock::$bodies[0]);

        $r = $this->post([Turnstile::FIELD => 'ok']);
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'), 'успішна перевірка — звичайна заявка');
        self::assertNull($this->lastLead()->getSpamReason());
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): Response
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));

        return $this->http('POST', 'https://' . $this->apex . '/lead', [
            '_token' => html_entity_decode($m[1]),
            'phone' => '+380 67 ' . random_int(100, 999) . ' 55 66',
        ] + $fields, ['REMOTE_ADDR' => '10.' . random_int(100, 199) . '.' . random_int(0, 255) . '.' . random_int(1, 254), 'HTTP_REFERER' => 'https://' . $this->apex . '/contacts/']);
    }

    private function lastLead(): ServiceLead
    {
        $this->em()->clear();
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->apexCityId], ['id' => 'DESC']);
        self::assertInstanceOf(ServiceLead::class, $lead);

        return $lead;
    }
}
