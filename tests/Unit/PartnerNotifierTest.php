<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCity;
use App\Entity\ServiceLead;
use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\ServiceSite\LeadFields;
use App\ServiceSite\Partners\HostResolver;
use App\ServiceSite\Partners\PartnerNotifier;
use App\ServiceSite\Partners\PartnersConfig;
use App\ServiceSite\Partners\WebhookSender;
use App\Settings\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;

final class PartnerNotifierTest extends TestCase
{
    public function testPartialDeliveryThenRetriesOnlyWebhook(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('flush');
        $calls = 0;
        $notifier = $this->notifier($mailer, $em, new MockHttpClient(static function () use (&$calls): MockResponse {
            return new MockResponse('', ['http_code' => ++$calls === 1 ? 500 : 204]);
        }));
        $assignment = $this->assignment();
        $before = time();
        $notifier->notify($assignment);
        self::assertNotNull($assignment->getDeliveredAt());
        self::assertNull($assignment->getWebhookDeliveredAt());
        self::assertSame(['webhook'], $assignment->pendingChannels());
        self::assertSame(1, $assignment->getAttempts());
        self::assertSame('HTTP 500', $assignment->getLastError());
        self::assertEqualsWithDelta($before + 300, $assignment->getNextAttemptAt()->getTimestamp(), 2);
        $emailAt = $assignment->getDeliveredAt();
        $notifier->notify($assignment);
        self::assertSame(2, $calls);
        self::assertSame($emailAt, $assignment->getDeliveredAt());
        self::assertNotNull($assignment->getWebhookDeliveredAt());
        self::assertTrue($assignment->isFullyDelivered());
        self::assertNull($assignment->getLastError());
        self::assertNull($assignment->getNextAttemptAt());
    }

    /** @return iterable<array{int, ?int}> */
    public static function backoffs(): iterable
    {
        yield [0, 300];
        yield [1, 900];
        yield [2, 3600];
        yield [3, 10800];
        yield [4, 21600];
        yield [5, null];
    }

    #[DataProvider('backoffs')]
    public function testBackoffAndSixthFailureStopsQueue(int $attempts, ?int $seconds): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $assignment = $this->assignment()->setAttempts($attempts)->setDeliveredAt(new \DateTimeImmutable());
        $before = time();
        $this->notifier($mailer, $em, new MockHttpClient(new MockResponse('', ['http_code' => 500])))->notify($assignment);
        self::assertSame($attempts + 1, $assignment->getAttempts());
        self::assertSame('HTTP 500', $assignment->getLastError());
        if ($seconds === null) {
            self::assertNull($assignment->getNextAttemptAt());
            self::assertFalse($assignment->isFullyDelivered());
        } else {
            self::assertEqualsWithDelta($before + $seconds, $assignment->getNextAttemptAt()->getTimestamp(), 2);
        }
    }

    public function testLeftPartnerDoesNotAttemptDelivery(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $assignment = $this->assignment()->setNextAttemptAt(new \DateTimeImmutable());
        $assignment->getPartner()->setStatus(ServicePartner::STATUS_LEFT);
        $client = new MockHttpClient(static fn () => self::fail('HTTP для left заборонений'));
        $this->notifier($mailer, $em, $client)->notify($assignment);
        self::assertSame(0, $assignment->getAttempts());
        self::assertNull($assignment->getNextAttemptAt());
        self::assertSame('компанія вибула', $assignment->getLastError());
    }

    public function testDisabledModuleDoesNotSendFlushOrPing(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $client = new MockHttpClient(static fn () => self::fail('Вимкнений модуль не виконує HTTP'));
        $notifier = $this->notifier($mailer, $em, $client, false);
        $assignment = $this->assignment();
        $notifier->notify($assignment);
        self::assertSame(0, $assignment->getAttempts());
        self::assertNotNull($notifier->ping($assignment->getPartner()));
    }

    public function testPayloadContainsConsentButNoIpAndEmptyDetailsAreObject(): void
    {
        $assignment = $this->assignment();
        $assignment->getLead()->setConsentText('Згода')->setConsentAt(new \DateTimeImmutable())->setConsentIp('192.0.2.1');
        $notifier = $this->notifier($this->createStub(MailerInterface::class), $this->createStub(EntityManagerInterface::class), new MockHttpClient());
        $payload = $notifier->payload($assignment);
        self::assertSame('lead.assigned', $payload['event']);
        self::assertSame('11968', $payload['lead']['zip_from']);
        self::assertSame('Згода', $payload['lead']['consent']['text']);
        self::assertSame($assignment->getLead()->getConsentAt()->format(\DateTimeInterface::ATOM), $payload['lead']['consent']['at']);
        self::assertArrayNotHasKey('ip', $payload['lead']['consent']);
        self::assertStringNotContainsString('192.0.2.1', json_encode($payload));
        self::assertInstanceOf(\stdClass::class, $payload['lead']['details']);
    }

    private function assignment(): ServiceLeadAssignment
    {
        $city = (new ServiceCity())->setHost('site.example');

        return (new ServiceLeadAssignment())->setLead((new ServiceLead())->setCity($city)->setPhone('+12025550123')->setZipFrom('11968'))
            ->setPartner((new ServicePartner())->setCity($city)->setNotifyEmails('a@example.test')->setWebhookUrl('https://partner.example/'));
    }

    private function notifier(MailerInterface $mailer, EntityManagerInterface $em, MockHttpClient $client, bool $enabled = true): PartnerNotifier
    {
        $resolver = $this->createStub(HostResolver::class);
        $resolver->method('ips')->willReturn(['93.184.216.34']);

        return new PartnerNotifier(new PartnersConfig(['enabled' => $enabled]), $mailer, new NullLogger(), new LeadFields(), $em,
            new WebhookSender($client, $resolver, new SecretBox()));
    }
}
