<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\LeadSpamGuard;
use App\ServiceSite\Turnstile;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cloudflare Turnstile (app.lead_spam.turnstile): без site_key чи секрету вимкнено; з ключами — відсутня відповідь і
 * success=false блокують, success=true проходить, недоступний Cloudflare чи internal-error пропускають заявку.
 */
final class TurnstileTest extends TestCase
{
    private const ENV = 'TURNSTILE_SECRET_TEST';

    protected function tearDown(): void
    {
        unset($_SERVER[self::ENV], $_ENV[self::ENV]);
    }

    /** @param list<MockResponse|\Closure> $responses */
    private function make(array $responses = [], string $siteKey = '0x4AAAAAAA', ?string $secret = 's3cret'): Turnstile
    {
        unset($_SERVER[self::ENV], $_ENV[self::ENV]);
        if ($secret !== null) {
            $_SERVER[self::ENV] = $secret;
        }

        return new Turnstile(['turnstile' => ['site_key' => $siteKey, 'secret_env' => self::ENV]], new MockHttpClient($responses), new NullLogger());
    }

    private function request(?string $token): Request
    {
        return Request::create('/lead', 'POST', $token === null ? ['phone' => '0671234567'] : ['phone' => '0671234567', Turnstile::FIELD => $token], [], [], ['REMOTE_ADDR' => '203.0.113.7']);
    }

    public function testDisabledWithoutKeys(): void
    {
        self::assertFalse($this->make(siteKey: '')->enabled());
        self::assertFalse($this->make(secret: null)->enabled());
        self::assertNull($this->make(siteKey: '')->verify($this->request(null)), 'вимкнено — нічого не перевіряємо');
        self::assertTrue($this->make()->enabled());
        self::assertSame('0x4AAAAAAA', $this->make()->siteKey());
    }

    public function testVerifyOutcomes(): void
    {
        self::assertSame('turnstile:missing', $this->make()->verify($this->request(null)));

        $seen = [];
        $ok = $this->make([function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['body']];

            return new MockResponse('{"success":true}');
        }]);
        self::assertNull($ok->verify($this->request('tok-1')));
        self::assertSame('POST', $seen[0]);
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $seen[1]);
        self::assertStringContainsString('secret=s3cret', (string) $seen[2]);
        self::assertStringContainsString('response=tok-1', (string) $seen[2]);
        self::assertStringContainsString('remoteip=203.0.113.7', (string) $seen[2]);

        self::assertSame('turnstile:failed', $this->make([new MockResponse('{"success":false,"error-codes":["invalid-input-response"]}')])->verify($this->request('bad')));
        self::assertNull($this->make([new MockResponse('{"success":false,"error-codes":["internal-error"]}')])->verify($this->request('x')), 'збій Cloudflare — пропускаємо');
        self::assertNull($this->make([new MockResponse('', ['http_code' => 503])])->verify($this->request('x')), '503 — пропускаємо');
        self::assertNull($this->make([new MockResponse('not json')])->verify($this->request('x')));
        self::assertNull($this->make([static fn () => throw new TransportException('timeout')])->verify($this->request('x')), 'таймаут — пропускаємо');
    }

    public function testGuardAsksTurnstileLast(): void
    {
        $calls = 0;
        $turnstile = $this->make([function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"success":false}');
        }]);
        $guard = new LeadSpamGuard([], 'test-secret', new NullLogger(), $turnstile);
        self::assertSame('honeypot', $guard->blockReason(Request::create('/lead', 'POST', ['website' => 'x', 'phone' => '0671234567'])));
        self::assertSame(0, $calls, 'дешеві перевірки — до запиту в Cloudflare');
        self::assertSame('turnstile:missing', $guard->blockReason(Request::create('/lead', 'POST', ['phone' => '0671234567'])));
        self::assertSame('turnstile:failed', $guard->blockReason(Request::create('/lead', 'POST', ['phone' => '0671234567', Turnstile::FIELD => 't'])));
        self::assertSame(1, $calls);
        // без ключів гард Turnstile не чіпає
        self::assertNull((new LeadSpamGuard([], 'test-secret', new NullLogger(), $this->make(siteKey: '')))->blockReason(Request::create('/lead', 'POST', ['phone' => '0671234567'])));
    }
}
