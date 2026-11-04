<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\ServiceSite\Partners\PartnerNotifier;
use App\ServiceSite\Partners\PartnersConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:partners:redeliver', description: 'Повторити недоставлені email та вебхуки партнерів')]
final class PartnersRedeliverCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PartnersConfig $config,
        private readonly PartnerNotifier $notifier,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Скільки передач обробити за раз')] int $limit = 50,
        #[Option('За скільки останніх днів брати передачі')] int $days = 3,
    ): int {
        if (!$this->config->enabled) {
            $io->warning('Модуль партнерів вимкнено (app.partners.enabled).');

            return 0;
        }
        if (!$this->em->isOpen()) {
            $io->error('EntityManager закрито — чергу візьме наступний прогін.');

            return 1;
        }
        $now = new \DateTimeImmutable();
        /** @var list<ServiceLeadAssignment> $pending */
        $pending = $this->em->createQueryBuilder()->select('a', 'p')->from(ServiceLeadAssignment::class, 'a')
            ->join('a.partner', 'p')
            ->where('a.nextAttemptAt IS NOT NULL')->andWhere('a.nextAttemptAt <= :now')
            ->andWhere('a.attempts < :max')->andWhere('a.assignedAt >= :since')->andWhere('a.assignedAt <= :fresh')
            ->andWhere('p.status != :left')->setParameter('left', ServicePartner::STATUS_LEFT)
            ->setParameter('now', $now)->setParameter('max', PartnerNotifier::MAX_ATTEMPTS)
            ->setParameter('since', $now->modify(sprintf('-%d days', max(1, $days))))
            // Першу доставку виконує запит форми; cron чекає дві хвилини.
            ->setParameter('fresh', $now->modify('-2 minutes'))
            ->orderBy('a.id', 'ASC')->setMaxResults(max(1, $limit))->getQuery()->getResult();
        $delivered = 0;
        $queued = 0;
        $failed = 0;
        foreach ($pending as $assignment) {
            if (!$this->em->isOpen()) {
                $io->error('EntityManager закрито після збою БД — решту черги візьме наступний прогін.');

                return 1;
            }
            $this->notifier->notify($assignment);
            if (!$this->em->isOpen()) {
                $io->error('EntityManager закрито після збою БД — стан доставки не збережено.');

                return 1;
            }
            if ($assignment->isFullyDelivered()) {
                ++$delivered;
            } elseif ($assignment->getNextAttemptAt() !== null) {
                ++$queued;
            } else {
                ++$failed;
            }
        }
        $io->success(sprintf('Доставлено повністю: %d; ще в черзі серед обраних: %d; вичерпано спроб: %d.', $delivered, $queued, $failed));

        return $delivered === \count($pending) ? 0 : 1;
    }
}
