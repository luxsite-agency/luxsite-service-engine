<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\LeadSpamGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class LeadSpamGuardTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private LeadSpamGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new LeadSpamGuard('test-secret', new NullLogger());
    }

    /** @param array<string, string> $post */
    private function reason(array $post): ?string
    {
        return $this->guard->blockReason(Request::create('/lead', 'POST', $post), now: self::NOW);
    }

    private function token(int $secondsAgo): string
    {
        return $this->guard->formToken(self::NOW - $secondsAgo);
    }

    public function testHumanLeadPasses(): void
    {
        self::assertNull($this->reason([
            '_ft' => $this->token(25),
            'phone' => '+38 (067) 123-45-67',
            'name' => "Мар'яна Іваненко-Петрук",
            'message' => 'Потрібно перевезти 2 палети, вага=500 кг; Київ - Львів. Деталі: https://example.com/spec',
        ]));
    }

    public function testFormWithoutTimeFieldStillWorks(): void
    {
        // шаблон паку без _ft не має ламати заявки
        self::assertNull($this->reason(['phone' => '0671234567', 'name' => 'Іван']));
    }

    public function testHoneypot(): void
    {
        self::assertSame('honeypot', $this->reason(['website' => 'x', 'phone' => '0671234567']));
    }

    public function testTooFastAndForgedToken(): void
    {
        self::assertSame('too-fast 1s', $this->reason(['_ft' => $this->token(1), 'phone' => '0671234567']));
        self::assertSame('time-token', $this->reason(['_ft' => (self::NOW - 60) . '.deadbeefdeadbeefdead', 'phone' => '0671234567']));
        self::assertSame('time-token', $this->reason(['_ft' => 'garbage', 'phone' => '0671234567']));
    }

    /** Реальні проби сканера з форми заявки (10.2026). */
    public function testScannerProbes(): void
    {
        $ok = ['_ft' => $this->token(30), 'phone' => '+380991234567'];

        self::assertSame('junk:name', $this->reason($ok + ['name' => "Test'||(SELECT 1 FROM (SELECT SLEEP(5))a)-- -"]));
        self::assertSame('junk:name', $this->reason($ok + ['name' => '+380501234567' . str_repeat('1', 200)]));
        self::assertSame('junk:name', $this->reason($ok + ['name' => '<script>alert(1)</script>']));
        self::assertSame('junk:name', $this->reason($ok + ['name' => 'Buy now https://spam.example']));
        self::assertSame('junk:phone', $this->reason(['_ft' => $this->token(30), 'phone' => "0501234567' AND SLEEP(5)--"]));
        self::assertSame('junk:message', $this->reason($ok + ['message' => "1' OR '1'='1"]));
        self::assertSame('junk:message', $this->reason($ok + ['message' => 'x UNION ALL SELECT password FROM users']));
        self::assertSame('junk:message', $this->reason($ok + ['message' => '<a href="https://spam.example">cheap</a>']));
        self::assertSame('junk:message', $this->reason($ok + ['message' => 'https://a.example https://b.example https://c.example']));
    }
}
