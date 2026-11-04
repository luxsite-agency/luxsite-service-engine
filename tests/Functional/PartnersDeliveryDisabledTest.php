<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;
use App\Entity\ServicePartner;
use App\Tests\Support\PartnersDisabledWebhookKernel;
use App\Tests\Support\PartnerWebhookMock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PartnersDeliveryDisabledTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return PartnersDisabledWebhookKernel::class;
    }

    protected function tearDown(): void
    {
        $this->em()->clear();
        $this->db()->executeStatement('DELETE FROM service_partner WHERE city_id IN (?, ?)', [$this->apexCityId, $this->branchCityId]);
        PartnerWebhookMock::$requests = [];
        parent::tearDown();
    }

    public function testDisabledModuleNeverCallsWebhookFromFormOrCron(): void
    {
        PartnerWebhookMock::$requests = [];
        $partner = (new ServicePartner())->setCity($this->city($this->apexCityId))->setName('Disabled Partner')
            ->setWebhookUrl('https://partner.example/leads');
        $this->em()->persist($partner);
        $this->em()->flush();
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('~<form[^>]*action="/lead"[^>]*>.*?name="_token" value="([^"]+)"~s', $page, $m));
        $response = $this->http('POST', 'https://' . $this->apex . '/lead', ['_token' => html_entity_decode($m[1]), 'phone' => '+12025550124'],
            ['REMOTE_ADDR' => '10.14.' . random_int(0, 255) . '.' . random_int(1, 254)]);
        self::assertTrue($response->isRedirect());
        $this->em()->clear();
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->apexCityId, 'phone' => '+12025550124']);
        self::assertNotNull($lead);
        self::assertNull($lead->getRouting());
        self::assertCount(0, $lead->getAssignments());
        $tester = new CommandTester((new Application(static::$kernel))->find('app:partners:redeliver'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Модуль партнерів вимкнено', $tester->getDisplay());
        self::assertCount(0, PartnerWebhookMock::$requests);
    }
}
