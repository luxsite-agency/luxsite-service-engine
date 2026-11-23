<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceLead;
use App\ServiceSite\Crm\LeadCrmConnector;

/**
 * Що відбувається зі збереженою заявкою далі: лист менеджерам міста (LeadNotifier) і CRM сайту (LeadCrmConnector,
 * best effort — помилку конектор пише в заявку, добере app:crm:sync). Один шлях для /lead, /calculator/lead і дії
 * «Не спам» в адмінці, щоб повернута з спаму заявка йшла так само, як звичайна. Спам (spam_reason) сюди не потрапляє.
 */
final class LeadDispatcher
{
    public function __construct(
        private readonly LeadNotifier $notifier,
        private readonly LeadCrmConnector $crm,
    ) {
    }

    /**
     * @param string $subjectPrefix «Заявка» або «Заявка з калькулятора»
     * @param string $host          хост сайту для адреси відправника, коли в бренду нема апекс-домену
     *
     * @return bool заявка пішла в CRM (crm_external_id заповнено)
     */
    public function dispatch(ServiceLead $lead, string $subjectPrefix, string $host): bool
    {
        if ($lead->isSpam()) {
            return false;
        }
        $this->notifier->notify($lead, $subjectPrefix, $host);

        return $this->crm->push($lead);
    }
}
