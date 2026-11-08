<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\LeadSpamGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Правила LeadSpamGuard (app.lead_spam): людська заявка проходить; honeypot, мітка часу (швидко, старе, підробка),
 * телефон (символи, цифри, країна), посилання, латиниця за мовою сторінки, проби сканера; кожне правило вимикається.
 */
final class LeadSpamGuardTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private LeadSpamGuard $guard;

    protected function setUp(): void
    {
        $this->guard = $this->make();
    }

    /** @param array<string, mixed> $config */
    private function make(array $config = []): LeadSpamGuard
    {
        return new LeadSpamGuard($config, 'test-secret', new NullLogger());
    }

    /** @param array<string, string> $post */
    private function reason(array $post, string $locale = 'uk', ?LeadSpamGuard $guard = null): ?string
    {
        return ($guard ?? $this->guard)->blockReason(Request::create('/lead', 'POST', $post), $locale, now: self::NOW);
    }

    private function token(int $secondsAgo): string
    {
        return $this->guard->formToken(self::NOW - $secondsAgo);
    }

    public function testDefaultsAndNormalize(): void
    {
        $c = LeadSpamGuard::normalize(['phone_country_codes' => ['+380', ' 0 ', ''], 'min_seconds' => '5', 'turnstile' => ['site_key' => ' k ']]);
        self::assertSame(['380', '0'], $c['phone_country_codes']);
        self::assertSame(5, $c['min_seconds']);
        self::assertSame(24, $c['max_age_hours']);
        self::assertSame(['site_key' => 'k', 'secret_env' => 'TURNSTILE_SECRET'], $c['turnstile']);
        self::assertSame(600, $this->guard->dedupSeconds());
        self::assertSame(5, $this->guard->ipPerHour());
        self::assertSame(90, $this->make(['dedup_seconds' => 90])->dedupSeconds());
    }

    public function testHumanLeadPasses(): void
    {
        self::assertNull($this->reason([
            '_ft' => $this->token(25),
            'phone' => '+38 (067) 123-45-67',
            'name' => "Мар'яна Іваненко-Петрук",
            'message' => 'Потрібно перевезти 2 палети, вага=500 кг; Київ - Львів. Деталі: https://example.com/spec',
        ]));
        // коротке повідомлення латиницею — не спам (latin_only лише від 40 символів)
        self::assertNull($this->reason(['_ft' => $this->token(25), 'phone' => '0671234567', 'message' => 'Call me after 18:00']));
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

    public function testTimeTokenFastOldAndForged(): void
    {
        self::assertSame('too-fast 1s', $this->reason(['_ft' => $this->token(1), 'phone' => '0671234567']));
        self::assertSame('time-old 30h', $this->reason(['_ft' => $this->token(30 * 3600 + 5), 'phone' => '0671234567']));
        self::assertNull($this->reason(['_ft' => $this->token(23 * 3600), 'phone' => '0671234567']));
        self::assertSame('time-token', $this->reason(['_ft' => (self::NOW - 60) . '.deadbeefdeadbeefdead', 'phone' => '0671234567']));
        self::assertSame('time-token', $this->reason(['_ft' => 'garbage', 'phone' => '0671234567']));

        // правила часу вимикаються (0), підпис перевіряється завжди
        $off = $this->make(['min_seconds' => 0, 'max_age_hours' => 0]);
        self::assertNull($this->reason(['_ft' => $this->token(0), 'phone' => '0671234567'], guard: $off));
        self::assertNull($this->reason(['_ft' => $this->token(90 * 3600), 'phone' => '0671234567'], guard: $off));
        self::assertSame('time-token', $this->reason(['_ft' => 'garbage', 'phone' => '0671234567'], guard: $off));

        self::assertTrue(LeadSpamGuard::isRetryReason('too-fast 1s'));
        self::assertTrue(LeadSpamGuard::isRetryReason('time-old 30h'));
        self::assertTrue(LeadSpamGuard::isRetryReason('phone:short'));
        self::assertFalse(LeadSpamGuard::isRetryReason('time-token'));
        self::assertFalse(LeadSpamGuard::isRetryReason('honeypot'));
        self::assertFalse(LeadSpamGuard::isRetryReason('phone:country'));
    }

    public function testPhoneDigitsAndCountryCodes(): void
    {
        self::assertSame('phone:short', $this->reason(['phone' => '067 123 45']));
        self::assertSame('phone:short', $this->reason([]));
        self::assertNull($this->reason(['phone' => '067 123 45 67']));
        self::assertNull($this->reason(['phone' => '12345'], guard: $this->make(['phone_min_digits' => 5])));
        self::assertSame('phone:short', $this->reason(['phone' => '1234'], guard: $this->make(['phone_min_digits' => 5])));

        $ua = $this->make(['phone_country_codes' => ['380', '0']]);
        self::assertNull($this->reason(['phone' => '+380 67 123 45 67'], guard: $ua));
        self::assertNull($this->reason(['phone' => '00380671234567'], guard: $ua));
        self::assertNull($this->reason(['phone' => '067 123 45 67'], guard: $ua));
        self::assertSame('phone:country', $this->reason(['phone' => '+1 212 555 0100'], guard: $ua));
        self::assertSame('phone:country', $this->reason(['phone' => '+7 495 123 45 67'], guard: $ua));
        self::assertSame('phone:country', $this->reason(['phone' => '0048 22 123 45 67'], guard: $ua), '00 — міжнародний префікс, не національний 0');
        // лише міжнародний формат
        self::assertSame('phone:country', $this->reason(['phone' => '067 123 45 67'], guard: $this->make(['phone_country_codes' => ['380']])));
        // порожній список — не перевіряти
        self::assertNull($this->reason(['phone' => '+1 212 555 0100']));
    }

    public function testUrlsInNameAndMessage(): void
    {
        $ok = ['_ft' => $this->token(30), 'phone' => '+380991234567'];
        self::assertSame('url:name', $this->reason($ok + ['name' => 'Buy now https://spam.example']));
        self::assertSame('url:name', $this->reason($ok + ['name' => 'Olga best-casino.xyz']));
        self::assertNull($this->reason($ok + ['name' => 'Олена (Львів)']));

        // кирилиця: спам від max_urls (3) посилань
        self::assertNull($this->reason($ok + ['message' => 'Дивіться https://a.example і www.b.example']));
        self::assertSame('url:message', $this->reason($ok + ['message' => 'Дивіться https://a.example https://b.example https://c.example']));
        self::assertNull($this->reason($ok + ['message' => 'Дивіться https://a.example https://b.example https://c.example'], guard: $this->make(['max_urls' => 4])));
        // без кирилиці на uk-сторінці — досить одного посилання
        self::assertSame('url:message', $this->reason($ok + ['message' => 'Best SEO offer: https://seo.example']));
        self::assertSame('url:message', $this->reason($ok + ['message' => 'Visit promo-site.top today']));
        // на en-сторінці посилання в англійському тексті — звичайна справа
        self::assertNull($this->reason($ok + ['message' => 'Specs are here: https://example.com/spec'], 'en'));
        // 0 — посилання не перевіряються ні в імені, ні в повідомленні
        $off = $this->make(['max_urls' => 0]);
        self::assertNull($this->reason($ok + ['name' => 'x https://spam.example', 'message' => 'Привіт https://a.example https://b.example https://c.example'], guard: $off));
    }

    public function testLatinOnlyByLocale(): void
    {
        $ok = ['_ft' => $this->token(30), 'phone' => '+380991234567'];
        $latin = 'Hello, I want to offer you our excellent services for your business growth today.';
        self::assertSame('latin-only', $this->reason($ok + ['message' => $latin]));
        self::assertSame('latin-only', $this->reason($ok + ['message' => $latin], 'ru'));
        self::assertNull($this->reason($ok + ['message' => $latin], 'en'), 'en-сайт: латиниця — норма');
        self::assertNull($this->reason($ok + ['message' => 'Прошу перевезти речі з Києва до Львова, дві шафи, ліжко і коробки, у суботу.']));
        self::assertNull($this->reason($ok + ['message' => 'Переклад документів: passport, diploma, certificate of marriage for Germany.']), 'трохи кирилиці достатньо');
        self::assertNull($this->reason($ok + ['message' => $latin], guard: $this->make(['latin_only' => false])));
        // з вимкненим latin_only посилання без кирилиці рахуються як звичайні (до max_urls)
        self::assertNull($this->reason($ok + ['message' => 'Best offer: https://seo.example'], guard: $this->make(['latin_only' => false])));
    }

    /** Реальні проби сканера з форми заявки (10.2026). */
    public function testScannerProbes(): void
    {
        $ok = ['_ft' => $this->token(30), 'phone' => '+380991234567'];

        self::assertSame('junk:name', $this->reason($ok + ['name' => "Test'||(SELECT 1 FROM (SELECT SLEEP(5))a)-- -"]));
        self::assertSame('junk:name', $this->reason($ok + ['name' => '+380501234567' . str_repeat('1', 200)]));
        self::assertSame('junk:name', $this->reason($ok + ['name' => '<script>alert(1)</script>']));
        self::assertSame('junk:phone', $this->reason(['_ft' => $this->token(30), 'phone' => "0501234567' AND SLEEP(5)--"]));
        self::assertSame('junk:message', $this->reason($ok + ['message' => "1' OR '1'='1"]));
        self::assertSame('junk:message', $this->reason($ok + ['message' => 'x UNION ALL SELECT password FROM users']));
        self::assertSame('junk:message', $this->reason($ok + ['message' => '<a href="https://spam.example">cheap</a>']));
    }

    /** Спам-заявка №60 procleaning (04.11.2026): 234 символи латиницею з посиланням, без utm, пройшла honeypot. */
    public function testProcleaningSpamSample(): void
    {
        $post = [
            '_ft' => $this->token(40),
            'phone' => '+380 93 000 00 00',
            'name' => 'Eric Jones',
            'message' => 'Hi, I just visited your website and I have a quick question. We help cleaning companies get more clients '
                . 'with a simple tool, no cost to try. Take a look here: https://leads-boost.example/demo and let me know what you think.',
        ];
        self::assertSame('url:message', $this->reason($post));
        self::assertSame('latin-only', $this->reason(['message' => str_replace(' https://leads-boost.example/demo', '', $post['message'])] + $post));
    }
}
