<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Crm;

use App\ServiceSite\Pipedrive\PipedriveCrm;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Активний конектор за параметром паку app.crm.system (none | pipedrive | bitrix24 | auto).
 *
 * auto (дефолт рушія, пак app.crm не задав) = поведінка до конекторів: Pipedrive, якщо в .env.local є
 * PIPEDRIVE_API_TOKEN, інакше none. Так сайти, що вже були на Pipedrive, лишаються на ньому без правок паку.
 *
 * Невідомий код - помилка конфігурації: пишемо critical і віддаємо none, а не 500 на формі заявки
 * (заявка важливіша за CRM: вона все одно в БД і в пошті). bin/site validate ловить це ще до деплою.
 */
final class LeadCrmRegistry
{
    public const AUTO = 'auto';

    /** @var array<string, LeadCrmConnector> */
    private array $connectors = [];
    private readonly string $system;

    /**
     * @param iterable<LeadCrmConnector> $connectors
     * @param array<string, mixed>       $config     app.crm
     */
    public function __construct(
        #[AutowireIterator('app.lead_crm')]
        iterable $connectors,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.crm%')]
        array $config = [],
    ) {
        foreach ($connectors as $connector) {
            $this->connectors[$connector->system()] = $connector;
        }
        $this->system = strtolower(trim((string) ($config['system'] ?? self::AUTO))) ?: self::AUTO;
    }

    /** Значення з паку як є (auto/none/pipedrive/…) - для повідомлень команди. */
    public function configured(): string
    {
        return $this->system;
    }

    public function active(): LeadCrmConnector
    {
        if ($this->system === self::AUTO) {
            $pipedrive = $this->connectors[PipedriveCrm::SYSTEM] ?? null;

            return $pipedrive !== null && $pipedrive->isEnabled() ? $pipedrive : $this->null();
        }

        $connector = $this->connectors[$this->system] ?? null;
        if ($connector === null) {
            $this->logger->critical(sprintf(
                'app.crm.system = «%s» - невідомий конектор (є: %s, auto). CRM вимкнено.',
                $this->system,
                implode(', ', array_keys($this->connectors)),
            ));

            return $this->null();
        }

        return $connector;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->connectors);
    }

    private function null(): LeadCrmConnector
    {
        return $this->connectors[NullCrm::SYSTEM] ?? new NullCrm();
    }
}
