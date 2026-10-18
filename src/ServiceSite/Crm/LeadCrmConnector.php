<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Crm;

use App\Entity\ServiceLead;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * CRM як конектор сайту: рушій не знає конкретної CRM, контролери й app:crm:sync працюють через цей контракт.
 *
 * Реалізації - теговані сервіси (app.lead_crm), активну обирає LeadCrmRegistry за параметром паку app.crm.system.
 * Нова CRM (KeyCRM, HubSpot…) = новий клас, що розширює AbstractLeadCrm; поля заявки вже нейтральні (crm_*).
 */
#[AutoconfigureTag('app.lead_crm')]
interface LeadCrmConnector
{
    /** Код системи: значення app.crm.system і service_lead.crm_system (none, pipedrive, bitrix24…). */
    public function system(): string;

    /** false = доступу нема (порожній токен/вебхук у .env.local) - нічого не шлемо, так стенд не пише в чужу CRM. */
    public function isEnabled(): bool;

    /**
     * Best effort: заявка вже в БД і в пошті, тож помилку конектор лише записує в заявку (crm_error) і лог -
     * її добере app:crm:sync. true = заявка тепер у CRM (crm_external_id заповнено).
     */
    public function push(ServiceLead $lead): bool;

    public function capabilities(): LeadCrmCapabilities;
}
