<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Entity\ServiceLead;
use App\ServiceSite\Crm\LeadCrmRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Добирає заявки, які не доїхали в CRM сайту (CRM лежала, токен протух, мережа моргнула). Черга - це самі
 * заявки в БД: у кого порожній crm_external_id, той ще не відправлений. CRM - активний конектор (app.crm.system).
 * Ставити в cron раз на 10 хвилин.
 *
 * Стара назва app:pipedrive:sync лишена аліасом: її можуть викликати наявні crontab-и на серверах.
 */
#[AsCommand(
    name: 'app:crm:sync',
    description: 'Відправити в CRM сайту заявки, які там ще не з\'явились',
    aliases: ['app:pipedrive:sync'],
)]
class CrmSyncCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeadCrmRegistry $registry,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Скільки заявок обробити за раз')] int $limit = 50,
        #[Option('За скільки останніх днів брати заявки')] int $days = 30,
    ): int {
        $crm = $this->registry->active();
        if (!$crm->isEnabled()) {
            $io->warning(sprintf(
                'CRM вимкнено (app.crm.system = %s, активний конектор %s): немає доступу в .env.local або CRM не обрано.',
                $this->registry->configured(),
                $crm->system(),
            ));

            return 0;
        }

        $since = new \DateTimeImmutable(sprintf('-%d days', max(1, $days)));
        /** @var list<ServiceLead> $pending */
        $pending = $this->em->createQueryBuilder()
            ->select('l')
            ->from(ServiceLead::class, 'l')
            ->where('l.crmExternalId IS NULL')
            // оброблені вручну назад у CRM не тягнемо
            ->andWhere('l.processed = false')
            ->andWhere('l.createdAt >= :since')
            // щойно створені відправляє сам запит форми; крон бере їх лише за 2 хв, щоб не поїхали двічі
            ->andWhere('l.createdAt <= :fresh')
            ->setParameter('since', $since)
            ->setParameter('fresh', new \DateTimeImmutable('-2 minutes'))
            ->orderBy('l.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        if ($pending === []) {
            $io->success('Черга порожня - усі заявки в CRM.');

            return 0;
        }

        $sent = 0;
        foreach ($pending as $lead) {
            if (!$this->em->isOpen()) {
                $io->error('EntityManager закрито після збою БД - решту черги візьме наступний прогін.');
                break;
            }
            if ($crm->push($lead)) {
                ++$sent;
                $io->writeln(sprintf('  #%d %s -> %s %s', $lead->getId(), $lead->getPhone(), $crm->system(), (string) $lead->getCrmExternalId()));
                continue;
            }

            $io->writeln(sprintf('  #%d %s - помилка: %s', $lead->getId(), $lead->getPhone(), (string) $lead->getCrmError()));
        }

        $io->success(sprintf('Відправлено %d із %d (%s).', $sent, \count($pending), $crm->system()));

        return $sent === \count($pending) ? 0 : 1;
    }
}
