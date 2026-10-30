<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceLead;
use App\ServiceSite\TelegramLeadNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class TelegramLeadNotifierTest extends TestCase
{
    public function testPhoneNormalizedForTapToCall(): void
    {
        self::assertSame('+380965593399', TelegramLeadNotifier::phone('096 559-33-99'));
        self::assertSame('+380965593399', TelegramLeadNotifier::phone('+38 (096) 559 33 99'));
        self::assertSame('12345', TelegramLeadNotifier::phone(' 12345 '), 'нестандартний номер - як ввели');
    }

    public function testTextHasFieldsAndEscapesHtml(): void
    {
        $lead = (new ServiceLead())
            ->setPhone('0965593399')
            ->setName('Олена <script>')
            ->setMessage("Лікар або послуга: Педіатр\nБажана дата: 31.10.2026");

        $text = TelegramLeadNotifier::text($lead, 'Заявка', 'https://invicta.luxsite.agency/pediatriia/');

        self::assertStringContainsString('<b>Заявка</b>', $text);
        self::assertStringContainsString("Ім'я: Олена &lt;script&gt;", $text);
        self::assertStringContainsString('Телефон: +380965593399', $text);
        self::assertStringContainsString("Лікар або послуга: Педіатр\nБажана дата: 31.10.2026", $text);
        self::assertStringContainsString('Сторінка: https://invicta.luxsite.agency/pediatriia/', $text);
    }

    public function testDisabledWithoutTokenOrChat(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('без токена запитів бути не може'));

        self::assertFalse((new TelegramLeadNotifier($http, new NullLogger(), 'token', ''))->isEnabled());
        self::assertFalse((new TelegramLeadNotifier($http, new NullLogger(), '', '-100'))->isEnabled());
        (new TelegramLeadNotifier($http, new NullLogger()))->notify((new ServiceLead())->setPhone('1'), 'Заявка', '-');
    }
}
