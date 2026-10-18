<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Crm;

use App\Entity\ServiceLead;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Спільна частина конекторів: запобіжники (вимкнено / уже відправлено), запис результату в заявку, best effort.
 * Конкретна CRM реалізує лише send(): створити запис і повернути його зовнішній id або кинути виняток.
 */
abstract class AbstractLeadCrm implements LeadCrmConnector
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /** @return string зовнішній id запису в CRM (лід, угода) */
    abstract protected function send(ServiceLead $lead): string;

    public function push(ServiceLead $lead): bool
    {
        if ($lead->getCrmExternalId() !== null) {
            return false;
        }

        if (!$this->isEnabled()) {
            $this->logger->info(sprintf('CRM вимкнено (%s): доступу нема в .env.local, заявку не відправлено.', $this->system()), [
                'lead_id' => $lead->getId(),
            ]);

            return false;
        }

        try {
            $externalId = $this->send($lead);

            $lead->setCrmExternalId(mb_substr($externalId, 0, 64))
                ->setCrmSystem($this->system())
                ->setCrmSyncedAt(new \DateTimeImmutable())
                ->setCrmError(null);
            $this->em->flush();

            return true;
        } catch (\Throwable $e) {
            $lead->setCrmError(mb_substr($e->getMessage(), 0, 500));
            try {
                $this->em->flush();
            } catch (\Throwable $flushError) {
                // заявка вже збережена; якщо впала сама БД - не перетворювати це на 500 для відвідувача
                $this->logger->error('Не вдалося записати crm_error: ' . $flushError->getMessage(), ['lead_id' => $lead->getId()]);
            }
            $this->logger->error(sprintf('Заявка не пішла в CRM (%s): %s', $this->system(), $e->getMessage()), [
                'lead_id' => $lead->getId(),
            ]);

            return false;
        }
    }
}
