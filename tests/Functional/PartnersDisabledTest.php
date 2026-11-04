<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;

/** Звичайне ядро: вимкнений модуль не змінює заявки та меню. */
final class PartnersDisabledTest extends SeoTestCase
{
    public function testDefaultKernelKeepsRoutingNullAndHidesPartnerAdmin(): void
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('~<form[^>]*action="/lead"[^>]*>.*?name="_token" value="([^"]+)"~s', $page, $m));
        $response = $this->http('POST', 'https://' . $this->apex . '/lead', ['_token' => html_entity_decode($m[1]),
            'phone' => '+12025550124', 'details' => ['zip_from' => '11968', 'zip_to' => '10001']],
            ['REMOTE_ADDR' => '10.12.' . random_int(0, 255) . '.' . random_int(1, 254)]);
        self::assertTrue($response->isRedirect());
        self::assertStringContainsString('thank-you', (string) $response->headers->get('Location'));
        $this->em()->clear();
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->apexCityId, 'phone' => '+12025550124']);
        self::assertNotNull($lead);
        self::assertNull($lead->getRouting());
        self::assertNull($lead->getZipFrom());
        self::assertNull($lead->getZipTo());
        self::assertNull($lead->getConsentText());
        self::assertNull($lead->getConsentIp());
        self::assertCount(0, $lead->getAssignments());
        self::assertEmailCount(0);
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $menu = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin')->getContent();
        self::assertStringNotContainsString('Передачі заявок', $menu);
        self::assertStringNotContainsString('>Партнери<', $menu);
        foreach (['/admin/service-partner', '/admin/service-lead-assignment', '/admin/service-lead/' . $lead->getId() . '/assign-partner'] as $path) {
            self::assertSame(404, $this->http('GET', 'https://' . $this->adminHost . $path)->getStatusCode(), $path);
        }
    }
}
