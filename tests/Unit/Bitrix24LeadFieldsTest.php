<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCity;
use App\Entity\ServiceLead;
use App\Entity\ServiceSite;
use App\Repository\ServiceItemRepository;
use App\ServiceSite\Bitrix24\Bitrix24Crm;
use App\ServiceSite\LeadFields;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Додаткові поля форми (app.lead_fields) доходять у Bitrix24: detail.<key>, email, details, обрана послуга. */
final class Bitrix24LeadFieldsTest extends TestCase
{
    /** @var array<string, array<string, mixed>> метод Bitrix24 => тіло запиту */
    private array $calls = [];

    private function crm(array $bitrix, ?LeadFields $fields): Bitrix24Crm
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $name = basename((string) parse_url($url, PHP_URL_PATH), '.json');
            $this->calls[$name] = json_decode((string) $options['body'], true);

            return new MockResponse((string) json_encode(['result' => match ($name) {
                'crm.duplicate.findbycomm' => [],
                'crm.contact.add' => 5,
                default => 77,
            }]));
        });

        return new Bitrix24Crm(
            $http,
            $this->createStub(ServiceItemRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            'https://portal.example/rest/1/testtok/',
            ['bitrix24' => $bitrix],
            $fields,
        );
    }

    private static function lead(?array $details, ?string $message = null): ServiceLead
    {
        $city = (new ServiceCity())->setHost('clinic.example')->setNameNomUk('Київ')->setSite((new ServiceSite())->setName('Клініка'));

        return (new ServiceLead())
            ->setCity($city)
            ->setPhone('+380501112233')
            ->setMessage($message)
            ->setDetails($details)
            ->setPageUrl('/');
    }

    public function testDetailsReachDealAndContact(): void
    {
        $fields = new LeadFields([
            ['key' => 'service', 'label' => ['uk' => 'Послуга'], 'type' => 'select', 'source' => 'services'],
            ['key' => 'date', 'label' => ['uk' => 'Дата'], 'type' => 'date'],
            ['key' => 'mail', 'label' => ['uk' => 'E-mail'], 'type' => 'email'],
        ]);
        $crm = $this->crm(['deal_fields' => [
            'UF_CRM_DATE' => 'detail.date',
            'UF_CRM_TITLE' => 'Запис: {service}, {detail.date}',
            'UF_CRM_DETAILS' => 'details',
            'UF_CRM_MISSING' => 'detail.nope',
        ]], $fields);

        $lead = self::lead(['service' => 'УЗД', 'date' => '2026-10-25', 'mail' => 'p@example.com'], "Болить\n\nПослуга: УЗД");
        self::assertTrue($crm->push($lead));
        self::assertSame('77', $lead->getCrmExternalId());

        $deal = $this->calls['crm.deal.add']['fields'];
        self::assertSame('2026-10-25', $deal['UF_CRM_DATE']);
        self::assertSame('Запис: УЗД, 2026-10-25', $deal['UF_CRM_TITLE']);
        self::assertSame("Послуга: УЗД\nДата: 25.10.2026\nE-mail: p@example.com", $deal['UF_CRM_DETAILS']);
        self::assertArrayNotHasKey('UF_CRM_MISSING', $deal);
        self::assertStringContainsString('Послуга: УЗД', $deal['COMMENTS'], 'обрана послуга — у зведенні для менеджера');
        self::assertSame([['VALUE' => 'p@example.com', 'VALUE_TYPE' => 'WORK']], $this->calls['crm.contact.add']['fields']['EMAIL']);
    }

    public function testWithoutLeadFieldsNothingChanges(): void
    {
        $crm = $this->crm(['deal_fields' => ['UF_CRM_DATE' => 'detail.date']], new LeadFields());
        self::assertTrue($crm->push(self::lead(null, 'Текст')));
        self::assertArrayNotHasKey('UF_CRM_DATE', $this->calls['crm.deal.add']['fields']);
        self::assertArrayNotHasKey('EMAIL', $this->calls['crm.contact.add']['fields']);
    }
}
