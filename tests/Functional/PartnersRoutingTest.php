<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;
use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\Tests\Support\PartnersKernel;
use Symfony\Component\HttpFoundation\Response;

final class PartnersRoutingTest extends SeoTestCase
{
    private int $partnerA;
    private int $partnerB;
    private int $pausedPartner;

    protected static function getKernelClass(): string
    {
        return PartnersKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $city = $this->city($this->apexCityId)->setLeadEmails('city@example.test');
        $a = (new ServicePartner())->setCity($city)->setName('A')->setCoverageZips(['119*'])->setPriority(10)
            ->setNotifyEmails('a@example.test')->setPricePerLead(2500);
        $b = (new ServicePartner())->setCity($city)->setName('B')->setCoverageZips(['11968']);
        $paused = (new ServicePartner())->setCity($city)->setName('Paused')->setStatus(ServicePartner::STATUS_PAUSED)->setPriority(100);
        foreach ([$a, $b, $paused] as $partner) {
            $this->em()->persist($partner);
        }
        $this->em()->flush();
        $this->partnerA = $a->getId();
        $this->partnerB = $b->getId();
        $this->pausedPartner = $paused->getId();
        $this->em()->clear();
    }

    protected function tearDown(): void
    {
        // RESTRICT захищає історію передач і міста; фікстуру прибираємо в порядку залежностей.
        $this->em()->clear();
        $this->db()->executeStatement('DELETE FROM service_lead_assignment WHERE partner_id IN (SELECT id FROM service_partner WHERE city_id IN (?, ?))', [$this->apexCityId, $this->branchCityId]);
        $this->db()->executeStatement('DELETE FROM service_partner WHERE city_id IN (?, ?)', [$this->apexCityId, $this->branchCityId]);
        parent::tearDown();
    }

    public function testFormRoutesToCoveredActivePartnersAndMailsA(): void
    {
        $response = $this->submit('11968');
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('thank-you', (string) $response->headers->get('Location'));
        $lead = $this->lastLead();
        self::assertSame('11968', $lead->getZipFrom());
        self::assertSame('10001', $lead->getZipTo());
        self::assertNotNull($lead->getConsentAt());
        self::assertSame('Погоджуюсь на передачу даних згідно з політикою конфіденційності', $lead->getConsentText());
        self::assertNotEmpty($lead->getConsentIp());
        self::assertSame('assigned', $lead->getRouting());
        $assignments = $lead->getAssignments()->toArray();
        self::assertCount(2, $assignments);
        self::assertSame([$this->partnerA, $this->partnerB], array_map(static fn (ServiceLeadAssignment $a): int => $a->getPartner()->getId(), $assignments));
        self::assertSame([1, 2], array_map(static fn (ServiceLeadAssignment $a): int => $a->getPosition(), $assignments));
        self::assertSame(2500, $assignments[0]->getPrice());
        self::assertNotNull($assignments[0]->getDeliveredAt());
        self::assertNull($assignments[1]->getDeliveredAt(), 'нема адреси партнера — нема доставки');
        // лист міста (LeadNotifier) і лист партнера A; у B нема адреси
        self::assertEmailCount(2);
        $mail = self::getMailerMessage(1);
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'a@example.test');
        self::assertSame('New lead #' . $lead->getId() . ': 11968 → 10001', $mail->getSubject());
        self::assertEmailTextBodyContains($mail, 'From ZIP: 11968');
        self::assertEmailTextBodyContains($mail, 'Consent: Погоджуюсь');
        self::assertMatchesRegularExpression('~Consent: .+ \(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d E[SD]T\)~', (string) $mail->getTextBody(), 'час згоди — у поясі паку (app.timezone)');
    }

    public function testUncoveredZipIsUnroutedAndNotifiesCity(): void
    {
        $this->submit('90210');
        $lead = $this->lastLead();
        self::assertSame('unrouted', $lead->getRouting());
        self::assertCount(0, $lead->getAssignments());
        self::assertEmailCount(2);
        $mail = self::getMailerMessage(1);
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'city@example.test');
        self::assertSame('Заявка без виконавця #' . $lead->getId() . ' (Головне)', $mail->getSubject());
    }

    public function testModeratorListsCompaniesAndManuallyAssignsUnroutedLeadWithCsrf(): void
    {
        $this->submit('90210');
        $leadId = $this->lastLead()->getId();
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        foreach (['/admin/service-partner', '/admin/service-lead-assignment'] as $path) {
            self::assertSame(200, $this->http('GET', 'https://' . $this->adminHost . $path)->getStatusCode(), $path);
        }
        $detail = $this->http('GET', 'https://' . $this->adminHost . '/admin/service-lead/' . $leadId);
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('Передати компанії', (string) $detail->getContent());
        $url = 'https://' . $this->adminHost . '/admin/service-lead/' . $leadId . '/assign-partner';
        $page = $this->http('GET', $url);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Paused', (string) $page->getContent(), 'призупиненого можна обрати вручну');
        $fields = self::formFields((string) $page->getContent(), 'form');
        self::assertArrayHasKey('form[_token]', $fields);

        $this->http('POST', $url, ['form' => ['partner' => $this->pausedPartner, '_token' => 'bad']]);
        self::assertCount(0, $this->lastLead()->getAssignments(), 'CSRF не дозволяє передачу');
        $fields['form[partner]'] = (string) $this->pausedPartner;
        $response = $this->http('POST', $url, self::nest($fields));
        self::assertTrue($response->isRedirect());
        self::assertStringContainsString('/admin/service-lead/' . $leadId, (string) $response->headers->get('Location'));
        $lead = $this->lastLead();
        self::assertSame('assigned', $lead->getRouting());
        self::assertCount(1, $lead->getAssignments());
        self::assertSame($this->pausedPartner, $lead->getAssignments()->first()->getPartner()->getId());
        self::assertSame(1, $lead->getAssignments()->first()->getPosition());
        $this->http('POST', $url, self::nest($fields));
        self::assertCount(1, $this->lastLead()->getAssignments(), 'повторна пара не дублюється');

        $fields['form[partner]'] = (string) $this->partnerA;
        $this->http('POST', $url, self::nest($fields));
        $lead = $this->lastLead();
        self::assertCount(2, $lead->getAssignments());
        self::assertSame(2, $lead->getAssignments()->last()->getPosition());
    }

    public function testAssignmentStatusEditStampsOnlyStatusChanges(): void
    {
        $this->submit('11968');
        $assignmentId = $this->lastLead()->getAssignments()->first()->getId();
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $url = 'https://' . $this->adminHost . '/admin/service-lead-assignment/' . $assignmentId . '/edit';
        $page = $this->http('GET', $url);
        self::assertSame(200, $page->getStatusCode());
        $fields = self::formFields((string) $page->getContent(), 'ServiceLeadAssignment');
        self::assertArrayNotHasKey('ServiceLeadAssignment[price]', $fields);
        $fields['ServiceLeadAssignment[status]'] = 'accepted';
        $fields['ServiceLeadAssignment[statusNote]'] = 'Зателефонували';
        self::assertTrue($this->http('POST', $url, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]])->isRedirect());
        $this->em()->clear();
        $assignment = $this->em()->find(ServiceLeadAssignment::class, $assignmentId);
        self::assertSame('accepted', $assignment->getStatus());
        self::assertNotNull($assignment->getStatusAt());
        $statusAt = new \DateTimeImmutable('2026-01-01 12:00:00');
        $assignment->setStatusAt($statusAt);
        $this->em()->flush();
        $page = $this->http('GET', $url);
        $fields = self::formFields((string) $page->getContent(), 'ServiceLeadAssignment');
        $fields['ServiceLeadAssignment[status]'] = 'accepted';
        $fields['ServiceLeadAssignment[statusNote]'] = 'Уточнення';
        self::assertTrue($this->http('POST', $url, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]])->isRedirect());
        $this->em()->clear();
        self::assertEquals($statusAt, $this->em()->find(ServiceLeadAssignment::class, $assignmentId)->getStatusAt());
    }

    private function submit(string $zip): Response
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('~<form[^>]*action="/lead"[^>]*>.*?name="_token" value="([^"]+)"~s', $page, $m));

        return $this->http('POST', 'https://' . $this->apex . '/lead', ['_token' => html_entity_decode($m[1]),
            'phone' => '+12025550123', 'name' => 'Test Customer', 'details' => ['zip_from' => $zip, 'zip_to' => '10001'], 'consent' => '1'],
            ['REMOTE_ADDR' => '10.11.' . random_int(0, 255) . '.' . random_int(1, 254), 'HTTP_REFERER' => 'https://' . $this->apex . '/contacts/']);
    }

    private function lastLead(): ServiceLead
    {
        $this->em()->clear();
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->apexCityId], ['id' => 'DESC']);
        self::assertInstanceOf(ServiceLead::class, $lead);

        return $lead;
    }
}
