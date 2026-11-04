<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Partners;

use App\Entity\ServiceLead;
use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\Repository\ServiceLeadAssignmentRepository;
use App\Repository\ServicePartnerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Передача заявки компаніям-партнерам міста (app.partners): кандидати за ZIP, статусом і добовим лімітом, порядок —
 * власна компанія, пріоритет, рівномірність; exclusive або власна — одна компанія, інакше до share_max. Без кандидатів —
 * routing = unrouted і лист на адреси міста.
 */
final class LeadRouter
{
    public function __construct(
        private readonly PartnersConfig $config,
        private readonly ServicePartnerRepository $partners,
        private readonly ServiceLeadAssignmentRepository $assignments,
        private readonly EntityManagerInterface $em,
        private readonly PartnerNotifier $notifier,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Для форм сайту: заявка вже збережена, тож збій маршрутизації не має давати клієнту 500 — лише запис у лог,
     * заявка лишається з routing = null і її видно в адмінці (передати вручну).
     *
     * @return list<ServiceLeadAssignment>
     */
    public function routeSafely(ServiceLead $lead): array
    {
        try {
            return $this->route($lead);
        } catch (\Throwable $e) {
            $this->logger?->error('Маршрутизація заявки партнерам не вдалася: ' . $e->getMessage(), ['lead_id' => $lead->getId()]);

            return [];
        }
    }

    /** @return list<ServiceLeadAssignment> */
    public function route(ServiceLead $lead, ?\DateTimeImmutable $now = null): array
    {
        if (!$this->config->enabled || $lead->getCity() === null) {
            return [];
        }
        // Повторний виклик не дублює передач і листів.
        if ($lead->getRouting() !== null) {
            return array_values($lead->getAssignments()->toArray());
        }
        // Час — у поясі PHP, як решта дат рушія (Doctrine не зберігає пояс); межа доби — у поясі паку (app.timezone).
        $local = new \DateTimeZone(date_default_timezone_get());
        $now = ($now ?? new \DateTimeImmutable())->setTimezone($local);
        $since = $now->setTimezone($this->config->timezone)->setTime(0, 0)->setTimezone($local);
        $candidates = [];
        $counts = [];
        foreach ($this->partners->activeForCity($lead->getCity()) as $partner) {
            if (!$partner->coversZip($lead->getZipFrom())) {
                continue;
            }
            $count = $this->assignments->countToday($partner, $since);
            if ($partner->getDailyLimit() !== null && $count >= $partner->getDailyLimit()) {
                continue;
            }
            $counts[$partner->getId()] = $count;
            $candidates[] = $partner;
        }
        $picked = self::pick($candidates, $counts, $this->config->shareMax);
        $created = [];
        foreach ($picked as $i => $partner) {
            $created[] = $this->createAssignment($lead, $partner, $i + 1, $now);
        }
        $lead->setRouting($created === [] ? ServiceLead::ROUTING_UNROUTED : ServiceLead::ROUTING_ASSIGNED);
        $this->em->flush();
        if ($created === []) {
            $this->notifier->notifyUnrouted($lead);
        }
        foreach ($created as $assignment) {
            $this->notifier->notify($assignment);
        }

        return $created;
    }

    /**
     * Кандидати вже пройшли перевірку міста, ZIP, статусу й ліміту.
     *
     * @param list<ServicePartner> $candidates
     * @param array<int, int> $todayCounts кількість передач за ID партнера
     * @return list<ServicePartner>
     */
    public static function pick(array $candidates, array $todayCounts, int $shareMax): array
    {
        usort($candidates, static fn (ServicePartner $a, ServicePartner $b): int =>
            ($b->isOwn() <=> $a->isOwn())
            ?: ($b->getPriority() <=> $a->getPriority())
            ?: (($todayCounts[$a->getId()] ?? 0) <=> ($todayCounts[$b->getId()] ?? 0))
            ?: ($a->getId() <=> $b->getId()));
        if ($candidates === []) {
            return [];
        }
        $first = $candidates[0];
        if ($first->isOwn() || $first->getModel() === ServicePartner::MODEL_EXCLUSIVE) {
            return [$first];
        }

        return array_slice(array_values(array_filter($candidates, static fn (ServicePartner $p): bool => $p->getModel() === ServicePartner::MODEL_SHARED)), 0, $shareMax);
    }

    public function assignManually(ServiceLead $lead, ServicePartner $partner): ?ServiceLeadAssignment
    {
        if (!$this->config->enabled) {
            return null;
        }
        if ($lead->getCity() === null || $partner->getCity() === null || $lead->getCity()->getId() !== $partner->getCity()->getId()
            || !\in_array($partner->getStatus(), [ServicePartner::STATUS_ACTIVE, ServicePartner::STATUS_PAUSED], true)) {
            throw new \InvalidArgumentException('Оберіть активну або призупинену компанію міста заявки.');
        }
        $existing = $this->assignments->findOneBy(['lead' => $lead, 'partner' => $partner]);
        if ($existing !== null) {
            if ($lead->getRouting() !== ServiceLead::ROUTING_ASSIGNED) {
                $lead->setRouting(ServiceLead::ROUTING_ASSIGNED);
                $this->em->flush();
            }

            return $existing;
        }
        $position = 0;
        foreach ($lead->getAssignments() as $assignment) {
            $position = max($position, $assignment->getPosition());
        }
        $assignment = $this->createAssignment($lead, $partner, $position + 1, new \DateTimeImmutable());
        $lead->setRouting(ServiceLead::ROUTING_ASSIGNED);
        $this->em->flush();
        $this->notifier->notify($assignment);

        return $assignment;
    }

    private function createAssignment(ServiceLead $lead, ServicePartner $partner, int $position, \DateTimeImmutable $now): ServiceLeadAssignment
    {
        $assignment = (new ServiceLeadAssignment())->setLead($lead)->setPartner($partner)->setPosition($position)
            ->setAssignedAt($now)->setPrice($partner->getPricePerLead());
        $lead->addAssignment($assignment);
        $this->em->persist($assignment);

        return $assignment;
    }
}
