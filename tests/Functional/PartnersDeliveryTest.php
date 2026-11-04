<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;
use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\Settings\SecretBox;
use App\Tests\Support\PartnersKernel;
use App\Tests\Support\PartnerWebhookMock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PartnersDeliveryTest extends SeoTestCase
{
    private int $partnerId;
    private const string SECRET = 'partner-test-secret';

    protected static function getKernelClass(): string
    {
        return PartnersKernel::class;
    }

    protected function setUp(): void
    {
        PartnerWebhookMock::$requests = [];
        PartnerWebhookMock::$status = 204;
        parent::setUp();
        $box = static::getContainer()->get(SecretBox::class);
        $partner = (new ServicePartner())->setCity($this->city($this->apexCityId))->setName('Webhook Partner')
            ->setWebhookUrl('https://partner.example/leads')->setWebhookSecret($box->encrypt(self::SECRET));
        $this->em()->persist($partner);
        $this->em()->flush();
        $this->partnerId = $partner->getId();
    }

    protected function tearDown(): void
    {
        $this->em()->clear();
        $this->db()->executeStatement('DELETE FROM service_lead_assignment WHERE partner_id IN (SELECT id FROM service_partner WHERE city_id IN (?, ?))', [$this->apexCityId, $this->branchCityId]);
        $this->db()->executeStatement('DELETE FROM service_partner WHERE city_id IN (?, ?)', [$this->apexCityId, $this->branchCityId]);
        PartnerWebhookMock::$requests = [];
        PartnerWebhookMock::$status = 204;
        parent::tearDown();
    }

    public function testFormDeliversSignedJsonWithoutConsentIp(): void
    {
        $assignment = $this->submit();
        self::assertCount(1, PartnerWebhookMock::$requests);
        $request = PartnerWebhookMock::$requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://partner.example/leads', $request['url']);
        $body = $request['options']['body'];
        $payload = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('lead.assigned', $payload['event']);
        self::assertSame($assignment->getId(), $payload['assignment_id']);
        self::assertSame('11968', $payload['lead']['zip_from']);
        self::assertSame('10001', $payload['lead']['details']['zip_to']);
        self::assertArrayNotHasKey('ip', $payload['lead']['consent']);
        self::assertArrayNotHasKey('consent_ip', $payload['lead']);
        self::assertNotNull($payload['lead']['consent']['at']);
        $headers = [];
        foreach ($request['options']['headers'] as $header) {
            [$name, $value] = explode(':', $header, 2);
            $headers[strtolower($name)] = trim($value);
        }
        self::assertSame('sha256=' . hash_hmac('sha256', $headers['x-lead-timestamp'] . '.' . $body, self::SECRET), $headers['x-lead-signature']);
        self::assertNotNull($assignment->getWebhookDeliveredAt());
        self::assertTrue($assignment->isFullyDelivered());
    }

    public function testCommandRetriesDueAssignmentsButSkipsFreshOldExhaustedAndLeft(): void
    {
        PartnerWebhookMock::$status = 500;
        $assignment = $this->submit();
        self::assertSame(1, $assignment->getAttempts());
        $assignment->setAssignedAt(new \DateTimeImmutable('-10 minutes'))->setNextAttemptAt(new \DateTimeImmutable('-1 minute'));
        $partner = $assignment->getPartner();
        foreach (['fresh', 'old', 'exhausted', 'future', 'left'] as $case) {
            $lead = (new ServiceLead())->setCity($this->city($this->apexCityId))->setPhone('+12025550124');
            $target = $partner;
            if ($case === 'left') {
                $target = (new ServicePartner())->setCity($partner->getCity())->setName('Left')->setStatus(ServicePartner::STATUS_LEFT)
                    ->setWebhookUrl('https://partner.example/left');
                $this->em()->persist($target);
            }
            $other = (new ServiceLeadAssignment())->setLead($lead)->setPartner($target)->setAttempts($case === 'exhausted' ? 6 : 1)
                ->setNextAttemptAt(new \DateTimeImmutable($case === 'future' ? '+1 hour' : '-1 minute'))
                ->setAssignedAt(new \DateTimeImmutable(match ($case) { 'fresh' => 'now', 'old' => '-4 days', default => '-10 minutes' }));
            $this->em()->persist($lead);
            $this->em()->persist($other);
        }
        $this->em()->flush();
        PartnerWebhookMock::$requests = [];
        PartnerWebhookMock::$status = 204;
        $tester = new CommandTester((new Application(static::$kernel))->find('app:partners:redeliver'));
        self::assertSame(0, $tester->execute(['--limit' => 50, '--days' => 3]));
        self::assertStringContainsString('Доставлено повністю: 1', $tester->getDisplay());
        self::assertCount(1, PartnerWebhookMock::$requests);
        // команда працює в тому ж ядрі, але з власним станом UnitOfWork — читаємо передачу заново
        $assignmentId = $assignment->getId();
        $this->em()->clear();
        $assignment = $this->em()->find(ServiceLeadAssignment::class, $assignmentId);
        self::assertNotNull($assignment->getWebhookDeliveredAt());
        self::assertNull($assignment->getNextAttemptAt());
    }

    public function testAdminPingAndRedeliverRequireCsrfAndShowResult(): void
    {
        PartnerWebhookMock::$status = 500;
        $assignment = $this->submit()->setAttempts(6)->setNextAttemptAt(null);
        $assignmentId = $assignment->getId();
        $this->em()->flush();
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        PartnerWebhookMock::$requests = [];
        PartnerWebhookMock::$status = 204;
        $base = 'https://' . $this->adminHost;
        $pingUrl = $base . '/admin/service-partner/' . $this->partnerId . '/ping-webhook';
        $retryUrl = $base . '/admin/service-lead-assignment/' . $assignmentId . '/redeliver';
        foreach ([$pingUrl, $retryUrl] as $url) {
            $page = $this->http('GET', $url);
            self::assertSame(200, $page->getStatusCode());
            $fields = self::formFields((string) $page->getContent(), 'form');
            self::assertArrayHasKey('form[_token]', $fields);
            $this->http('POST', $url, ['form' => ['_token' => 'bad']]);
            self::assertCount($url === $pingUrl ? 0 : 1, PartnerWebhookMock::$requests);
            $response = $this->http('POST', $url, self::nest($fields));
            self::assertTrue($response->isRedirect());
            $detail = $this->http('GET', (string) $response->headers->get('Location'));
            self::assertStringContainsString($url === $pingUrl ? 'Вебхук успішно перевірено.' : 'Усі канали доставлено.', (string) $detail->getContent());
        }
        self::assertCount(2, PartnerWebhookMock::$requests);
        self::assertSame('ping', json_decode(PartnerWebhookMock::$requests[0]['options']['body'], true)['event']);
        $this->em()->clear();
        $saved = $this->em()->find(ServiceLeadAssignment::class, $assignmentId);
        self::assertSame(0, $saved->getAttempts());
        self::assertNotNull($saved->getWebhookDeliveredAt());
        self::assertNull($saved->getNextAttemptAt());
    }

    public function testAdminSecretIsEncryptedPreservedAndExplicitlyCleared(): void
    {
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $url = 'https://' . $this->adminHost . '/admin/service-partner/' . $this->partnerId . '/edit';
        $page = $this->http('GET', $url);
        self::assertStringNotContainsString(self::SECRET, (string) $page->getContent());
        $fields = self::formFields((string) $page->getContent(), 'ServicePartner');
        self::assertSame('', $fields['ServicePartner[webhookSecretInput]']);
        $fields['ServicePartner[webhookSecretInput]'] = 'new-webhook-secret';
        $this->savePartner($url, $fields);
        $envelope = $this->db()->fetchOne('SELECT webhook_secret FROM service_partner WHERE id = ?', [$this->partnerId]);
        self::assertNotSame('new-webhook-secret', $envelope);
        self::assertTrue(SecretBox::isEnvelope($envelope));
        self::assertSame('new-webhook-secret', static::getContainer()->get(SecretBox::class)->decrypt($envelope));
        $fields = self::formFields((string) $this->http('GET', $url)->getContent(), 'ServicePartner');
        $this->savePartner($url, $fields);
        self::assertSame($envelope, $this->db()->fetchOne('SELECT webhook_secret FROM service_partner WHERE id = ?', [$this->partnerId]));
        $fields = self::formFields((string) $this->http('GET', $url)->getContent(), 'ServicePartner');
        $fields['ServicePartner[clearWebhookSecret]'] = '1';
        $this->savePartner($url, $fields);
        self::assertNull($this->db()->fetchOne('SELECT webhook_secret FROM service_partner WHERE id = ?', [$this->partnerId]));
    }

    public function testUndeliveredFilterIncludesQueueAndExhaustedButExcludesDelivered(): void
    {
        PartnerWebhookMock::$status = 500;
        $queued = $this->submit();
        $ids = ['queued' => $queued->getId()];
        foreach (['exhausted', 'delivered'] as $case) {
            $lead = (new ServiceLead())->setCity($this->city($this->apexCityId))->setPhone('+12025550124');
            $assignment = (new ServiceLeadAssignment())->setLead($lead)->setPartner($queued->getPartner())->setAttempts(6);
            if ($case === 'delivered') {
                $assignment->setWebhookDeliveredAt(new \DateTimeImmutable());
            }
            $this->em()->persist($lead);
            $this->em()->persist($assignment);
            $this->em()->flush();
            $ids[$case] = $assignment->getId();
        }
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $url = 'https://' . $this->adminHost . '/admin/service-lead-assignment?'
            . http_build_query(['filters' => ['undelivered' => ['value' => '1']]]);
        $response = $this->http('GET', $url);
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('data-id="' . $ids['queued'] . '"', $html);
        self::assertStringContainsString('data-id="' . $ids['exhausted'] . '"', $html);
        self::assertStringNotContainsString('data-id="' . $ids['delivered'] . '"', $html);
    }

    public function testAdminPingFailureShowsErrorFlash(): void
    {
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        PartnerWebhookMock::$status = 500;
        $url = 'https://' . $this->adminHost . '/admin/service-partner/' . $this->partnerId . '/ping-webhook';
        $fields = self::formFields((string) $this->http('GET', $url)->getContent(), 'form');
        $response = $this->http('POST', $url, self::nest($fields));
        self::assertTrue($response->isRedirect());
        $detail = $this->http('GET', (string) $response->headers->get('Location'));
        self::assertStringContainsString('HTTP 500', (string) $detail->getContent());
        self::assertCount(1, PartnerWebhookMock::$requests);
    }

    private function savePartner(string $url, array $fields): void
    {
        // formFields читає input/textarea; обов'язкові select додаємо явно.
        $fields['ServicePartner[city]'] = (string) $this->apexCityId;
        $fields['ServicePartner[status]'] = 'active';
        $fields['ServicePartner[model]'] = 'shared';
        self::assertTrue($this->http('POST', $url, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]])->isRedirect());
    }

    private function submit(): ServiceLeadAssignment
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('~<form[^>]*action="/lead"[^>]*>.*?name="_token" value="([^"]+)"~s', $page, $m));
        $response = $this->http('POST', 'https://' . $this->apex . '/lead', ['_token' => html_entity_decode($m[1]),
            'phone' => '+12025550123', 'name' => 'Test Customer', 'details' => ['zip_from' => '11968', 'zip_to' => '10001'], 'consent' => '1'],
            ['REMOTE_ADDR' => '10.13.' . random_int(0, 255) . '.' . random_int(1, 254)]);
        self::assertTrue($response->isRedirect());
        self::assertStringContainsString('thank-you', (string) $response->headers->get('Location'));
        $this->em()->clear();

        return $this->em()->getRepository(ServiceLeadAssignment::class)->findOneBy(['partner' => $this->partnerId], ['id' => 'DESC'])
            ?? throw new \LogicException('Передачу не створено');
    }
}
