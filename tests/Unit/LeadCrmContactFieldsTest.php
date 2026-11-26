<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceLead;
use App\Repository\ServiceItemRepository;
use App\ServiceSite\Bitrix24\Bitrix24Crm;
use App\ServiceSite\LeadExtraFields;
use App\ServiceSite\Pipedrive\PipedriveClient;
use App\ServiceSite\Pipedrive\PipedriveCrm;
use App\ServiceSite\Telegram\TelegramCrm;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Email і довільні поля заявки (app.lead.extra_fields) у CRM: email — у контакт Pipedrive і Bitrix24 (джерело `email`
 * мапи contact_fields), рядки «Email: …» і «<підпис>: …» — у примітку/коментар/повідомлення.
 */
final class LeadCrmContactFieldsTest extends TestCase
{
    public function testTelegramMessageHasEmailAndExtraLines(): void
    {
        $text = '';
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$text): MockResponse {
            $text = (string) json_decode((string) $options['body'], true)['text'];

            return new MockResponse(json_encode(['ok' => true, 'result' => ['message_id' => 1]]));
        });
        $crm = new TelegramCrm($http, $this->createStub(EntityManagerInterface::class), new NullLogger(), '123:ABC-def_GHI', '-100500');
        $crm->setLeadExtraFields($this->extraFields());

        self::assertTrue($crm->push($this->lead()));
        self::assertStringContainsString('Email: a@b.co', $text);
        self::assertStringContainsString('Адреса будинку: 390 Edge Of Woods', $text);
        self::assertStringContainsString('showing_day: Saturday', $text);

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$text): MockResponse {
            $text = (string) json_decode((string) $options['body'], true)['text'];

            return new MockResponse(json_encode(['ok' => true, 'result' => ['message_id' => 2]]));
        });
        $crm = new TelegramCrm($http, $this->createStub(EntityManagerInterface::class), new NullLogger(), '123:ABC-def_GHI', '-100500');
        self::assertTrue($crm->push((new ServiceLead())->setPhone('+380671112233')));
        self::assertStringNotContainsString('Email', $text, 'заявка без email — повідомлення як було');
    }

    public function testPipedrivePersonGetsEmailAndNoteHasExtraLines(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $calls[$method . ' ' . basename($path)] = json_decode((string) ($options['body'] ?? 'null'), true);
            $data = match (true) {
                str_ends_with($path, 'persons/search') => ['items' => []],
                str_ends_with($path, '/persons') => ['id' => 5],
                str_ends_with($path, '/leads') => ['id' => 'lead-uuid'],
                default => ['id' => 1],
            };

            return new MockResponse(json_encode(['success' => true, 'data' => $data]));
        });
        $crm = new PipedriveCrm(new PipedriveClient($http, 'ci-token'), $this->createStub(EntityManagerInterface::class), new NullLogger());
        $crm->setLeadExtraFields($this->extraFields());

        self::assertTrue($crm->push($this->lead()), (string) $this->lead()->getCrmError());
        self::assertSame([['value' => 'a@b.co', 'primary' => true, 'label' => 'work']], $calls['POST persons']['email'] ?? null);
        $note = (string) ($calls['POST notes']['content'] ?? '');
        self::assertStringContainsString('Email: a@b.co', $note);
        self::assertStringContainsString('Адреса будинку: 390 Edge Of Woods', $note);
    }

    public function testBitrixContactGetsEmailFromLead(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {
            $name = basename((string) parse_url($url, \PHP_URL_PATH), '.json');
            $calls[$name] = json_decode((string) ($options['body'] ?? 'null'), true);

            return new MockResponse(json_encode(['result' => match ($name) {
                'crm.duplicate.findbycomm' => [],
                'crm.contact.add' => 7,
                default => 9,
            }]));
        });
        $crm = new Bitrix24Crm($http, $this->createStub(ServiceItemRepository::class), $this->createStub(EntityManagerInterface::class), new NullLogger(), 'https://ci.bitrix24.example/rest/1/citokencitoken/');
        $crm->setLeadExtraFields($this->extraFields());

        $lead = $this->lead();
        self::assertTrue($crm->push($lead), (string) $lead->getCrmError());
        self::assertSame([['VALUE' => 'a@b.co', 'VALUE_TYPE' => 'WORK']], $calls['crm.contact.add']['fields']['EMAIL'] ?? null);
        self::assertStringContainsString('Адреса будинку: 390 Edge Of Woods', (string) json_encode($calls['crm.deal.add'], \JSON_UNESCAPED_UNICODE));
    }

    private function lead(): ServiceLead
    {
        return (new ServiceLead())->setPhone('+380671112233')->setName('Ann')->setEmail('a@b.co')
            ->setExtra(['address' => '390 Edge Of Woods', 'showing_day' => 'Saturday']);
    }

    private function extraFields(): LeadExtraFields
    {
        return new LeadExtraFields([['key' => 'address', 'label' => 'Адреса будинку'], 'showing_day']);
    }
}
