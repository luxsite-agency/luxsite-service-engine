<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Crm;

use App\Entity\ServiceLead;
use App\ServiceSite\LeadFiles;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;

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

    private ?LeadFiles $leadFiles = null;

    /** Сетер, а не аргумент конструктора: конектори паків і наявні підкласи не міняють сигнатур */
    #[Required]
    public function setLeadFiles(LeadFiles $leadFiles): void
    {
        $this->leadFiles = $leadFiles;
    }

    /**
     * Рядки для опису в CRM: «Звідки: …» (context форми) і файли посиланнями на адмінку. Послугу кожен конектор бере сам
     * (Bitrix24 — у поле service). Порожньо — у заявці цих даних нема.
     *
     * @return list<string>
     */
    protected function detailLines(ServiceLead $lead): array
    {
        $rows = [];
        if ($lead->getContext() !== null) {
            $rows[] = 'Звідки: ' . $lead->getContext();
        }
        $files = $this->leadFiles?->lines($lead->getFiles()) ?? [];
        if ($files !== []) {
            $rows[] = 'Файли (в адмінці сайту):';
            array_push($rows, ...$files);
        }

        return $rows;
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
