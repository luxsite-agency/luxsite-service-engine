<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceLead;
use App\ServiceSite\Telegram\TelegramCrm;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Конектор Telegram: запит до Bot API, запис message_id у заявку, вимкнення без доступів, токен не тече в помилку. */
final class TelegramCrmTest extends TestCase
{
    private const TOKEN = '123456:ABC-def_GHI';

    public function testDisabledWithoutCredentials(): void
    {
        self::assertFalse($this->crm(new MockHttpClient(), '', '1')->isEnabled());
        self::assertFalse($this->crm(new MockHttpClient(), self::TOKEN, '')->isEnabled());
        self::assertTrue($this->crm(new MockHttpClient(), self::TOKEN, '-100500')->isEnabled());
    }

    public function testPushSendsMessageAndStoresMessageId(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $options['body'], true)];

            return new MockResponse(json_encode(['ok' => true, 'result' => ['message_id' => 77]]));
        });
        $lead = (new ServiceLead())->setPhone('+380671112233')->setName('Іван')->setService('Сонячна станція')->setMessage('Передзвоніть');

        self::assertTrue($this->crm($http, self::TOKEN, '-100500')->push($lead));

        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.telegram.org/bot' . self::TOKEN . '/sendMessage', $captured['url']);
        self::assertSame('-100500', $captured['body']['chat_id']);
        foreach (['Іван', '+380671112233', 'Сонячна станція', 'Передзвоніть'] as $part) {
            self::assertStringContainsString($part, $captured['body']['text']);
        }
        self::assertSame('77', $lead->getCrmExternalId());
        self::assertSame('telegram', $lead->getCrmSystem());
        self::assertNull($lead->getCrmError());
    }

    public function testApiErrorIsRecordedWithoutToken(): void
    {
        $http = new MockHttpClient(new MockResponse(
            json_encode(['ok' => false, 'description' => 'Bad Request: chat not found at /bot' . self::TOKEN]),
            ['http_code' => 400],
        ));
        $lead = (new ServiceLead())->setPhone('+380671112233');

        self::assertFalse($this->crm($http, self::TOKEN, '-1')->push($lead));

        self::assertNull($lead->getCrmExternalId());
        self::assertStringContainsString('chat not found', (string) $lead->getCrmError());
        self::assertStringNotContainsString('ABC-def_GHI', (string) $lead->getCrmError());
    }

    public function testNothingSentWhenDisabled(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('запит не мав піти'));
        $lead = (new ServiceLead())->setPhone('+380671112233');

        self::assertFalse($this->crm($http, '', '')->push($lead));
        self::assertNull($lead->getCrmExternalId());
    }

    private function crm(MockHttpClient $http, string $token, string $chat): TelegramCrm
    {
        return new TelegramCrm($http, $this->createStub(EntityManagerInterface::class), new NullLogger(), $token, $chat);
    }
}
