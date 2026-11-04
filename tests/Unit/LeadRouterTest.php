<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCity;
use App\Entity\ServiceLead;
use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\Repository\ServiceLeadAssignmentRepository;
use App\Repository\ServicePartnerRepository;
use App\ServiceSite\LeadFields;
use App\ServiceSite\Partners\LeadRouter;
use App\ServiceSite\Partners\PartnerNotifier;
use App\ServiceSite\Partners\PartnersConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class LeadRouterTest extends TestCase
{
    /** @return iterable<string, array{?array, ?string, bool}> */
    public static function coverageCases(): iterable
    {
        yield 'точний' => [['11968'], '11968', true];
        yield 'інший' => [['11968'], '11969', false];
        yield 'префікс' => [['119*'], '11968', true];
        yield 'інший префікс' => [['119*'], '10001', false];
        yield 'порожній список' => [[], '90210', true];
        yield 'усе місто' => [null, '90210', true];
        yield 'без ZIP усе місто' => [null, null, true];
        yield 'без ZIP порожній список' => [[], null, true];
        yield 'без ZIP обмежене покриття' => [['119*'], null, false];
        yield 'порожній ZIP обмежене покриття' => [['11968'], '', false];
        yield 'зберігає початковий нуль' => [['001*'], '00123', true];
    }

    #[DataProvider('coverageCases')]
    public function testCoverage(?array $coverage, ?string $zip, bool $expected): void
    {
        self::assertSame($expected, (new ServicePartner())->setCoverageZips($coverage)->coversZip($zip));
    }

    public function testOwnComesFirstAndTakesLeadAlone(): void
    {
        $own = self::partner(2)->setIsOwn(true)->setPriority(-10);
        self::assertSame([$own], LeadRouter::pick([self::partner(1)->setPriority(100), $own], [2 => 99], 3));
    }

    public function testPriorityThenDailyCountsThenId(): void
    {
        $a = self::partner(1);
        $b = self::partner(2);
        $c = self::partner(3)->setPriority(10);
        $d = self::partner(4);
        self::assertSame([$c, $b, $d, $a], LeadRouter::pick([$a, $d, $b, $c], [1 => 5, 2 => 1, 3 => 99, 4 => 1], 10));
    }

    public function testExclusiveFirstTakesLeadAlone(): void
    {
        $exclusive = self::partner(2)->setModel(ServicePartner::MODEL_EXCLUSIVE)->setPriority(10);
        self::assertSame([$exclusive], LeadRouter::pick([self::partner(1), $exclusive], [], 3));
    }

    public function testExclusiveLaterIsSkippedAndSharedLimitApplies(): void
    {
        $a = self::partner(1);
        $b = self::partner(2)->setModel(ServicePartner::MODEL_EXCLUSIVE);
        $c = self::partner(3);
        self::assertSame([$a, $c], LeadRouter::pick([$c, self::partner(4), $b, $a], [], 2));
        self::assertSame([$a], LeadRouter::pick([$a, $c], [], 1));
        self::assertSame([], LeadRouter::pick([], [], 3));
    }

    public function testRouteUsesLocalMidnightAndSkipsDailyLimitAndZip(): void
    {
        $config = new PartnersConfig(['enabled' => true], 'America/New_York');
        $partners = $this->createMock(ServicePartnerRepository::class);
        $assignments = $this->createMock(ServiceLeadAssignmentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $notifier = new PartnerNotifier($config, $mailer, $this->createStub(LoggerInterface::class), new LeadFields(), $em);
        $router = new LeadRouter($config, $partners, $assignments, $em, $notifier);
        $city = new ServiceCity();
        $full = self::partner(1)->setDailyLimit(1);
        $outside = self::partner(2)->setCoverageZips(['902*']);
        $available = self::partner(3)->setDailyLimit(2)->setPricePerLead(1500);
        $partners->expects(self::once())->method('activeForCity')->with($city)->willReturn([$full, $outside, $available]);
        $assignments->expects(self::exactly(2))->method('countToday')->willReturnCallback(static function (ServicePartner $p, \DateTimeImmutable $since): int {
            // О 03:30 UTC у Нью-Йорку ще попередня доба (EDT).
            self::assertSame((new \DateTimeImmutable('2026-07-01 04:00:00 UTC'))->getTimestamp(), $since->getTimestamp());
            self::assertSame(date_default_timezone_get(), $since->getTimezone()->getName(), 'межа доби — у поясі PHP, як дати в БД');
            self::assertContains($p->getId(), [1, 3]);

            return 1;
        });
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(ServiceLeadAssignment::class));
        $em->expects(self::once())->method('flush');
        $lead = (new ServiceLead())->setCity($city)->setZipFrom('11968');
        $now = new \DateTimeImmutable('2026-07-02 03:30:00 UTC');
        $result = $router->route($lead, $now);
        self::assertCount(1, $result);
        self::assertSame($available, $result[0]->getPartner());
        self::assertSame(1, $result[0]->getPosition());
        self::assertSame(1500, $result[0]->getPrice());
        self::assertSame('new', $result[0]->getStatus());
        self::assertEquals($now, $result[0]->getAssignedAt());
        self::assertNull($result[0]->getDeliveredAt());
        self::assertSame('assigned', $lead->getRouting());
        self::assertSame($result, $router->route($lead, $now), 'повторний виклик нічого не дублює');
    }

    public function testDisabledOrNoCityDoesNotQueryOrFlush(): void
    {
        foreach ([false, true] as $enabled) {
            $config = new PartnersConfig(['enabled' => $enabled]);
            $partners = $this->createMock(ServicePartnerRepository::class);
            $partners->expects(self::never())->method('activeForCity');
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects(self::never())->method('flush');
            $mailer = $this->createMock(MailerInterface::class);
            $mailer->expects(self::never())->method('send');
            $notifier = new PartnerNotifier($config, $mailer, $this->createStub(LoggerInterface::class), new LeadFields(), $em);
            $router = new LeadRouter($config, $partners, $this->createStub(ServiceLeadAssignmentRepository::class), $em, $notifier);
            $lead = new ServiceLead();
            if (!$enabled) {
                $lead->setCity(new ServiceCity());
            }
            self::assertSame([], $router->route($lead));
            self::assertNull($lead->getRouting());
        }
    }

    public function testMailFailureKeepsDeliveryNullAndLogsError(): void
    {
        $config = new PartnersConfig(['enabled' => true]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException(new TransportException('SMTP unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $assignment = (new ServiceLeadAssignment())->setLead((new ServiceLead())->setCity((new ServiceCity())->setHost('example.test')))
            ->setPartner(self::partner(1)->setNotifyEmails('a@example.test'));
        (new PartnerNotifier($config, $mailer, $logger, new LeadFields(), $em))->notify($assignment);
        self::assertNull($assignment->getDeliveredAt());
    }

    public function testSuccessfulDeliveryContainsConsentInPackTimezone(): void
    {
        $config = new PartnersConfig(['enabled' => true], 'America/New_York');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(static function (Email $email): bool {
            self::assertSame('New lead #42: 11968 → 10001', $email->getSubject());
            self::assertStringContainsString('Consent: Текст згоди (2026-11-04 10:00:00 EST)', $email->getTextBody());
            self::assertCount(2, $email->getTo());

            return true;
        }));
        $lead = (new ServiceLead())->setCity((new ServiceCity())->setHost('example.test'))->setZipFrom('11968')->setZipTo('10001')
            ->setConsentText('Текст згоди')->setConsentAt(new \DateTimeImmutable('2026-11-04 10:00:00 America/New_York'));
        (new \ReflectionProperty(ServiceLead::class, 'id'))->setValue($lead, 42);
        $assignment = (new ServiceLeadAssignment())->setLead($lead)->setPartner(self::partner(1)->setNotifyEmails('a@example.test, b@example.test'));
        (new PartnerNotifier($config, $mailer, $this->createStub(LoggerInterface::class), new LeadFields(), $em))->notify($assignment);
        self::assertNotNull($assignment->getDeliveredAt());
    }

    public function testCoverageTextareaAndZipNormalization(): void
    {
        $partner = (new ServicePartner())->setCoverageZipsText("11968\r\n 119*\n\n11968");
        self::assertSame(['11968', '119*'], $partner->getCoverageZips());
        self::assertSame("11968\n119*", $partner->getCoverageZipsText());
        self::assertSame('0012345678', PartnersConfig::normalizeZip('00-123 45678901'));
        self::assertNull(PartnersConfig::normalizeZip('abc'));
        self::assertFalse($partner->setStatus('paused')->isActive());
    }

    private static function partner(int $id): ServicePartner
    {
        $partner = (new ServicePartner())->setName('Partner ' . $id);
        (new \ReflectionProperty(ServicePartner::class, 'id'))->setValue($partner, $id);

        return $partner;
    }
}
