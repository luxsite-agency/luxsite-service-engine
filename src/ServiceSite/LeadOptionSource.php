<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Джерело варіантів для поля форми заявки type: select (app.lead_fields: source: <name()>).
 * Рушій має services (послуги міста) і team (команда міста); новий розділ (лікарі, філії, майстри) додає свій клас
 * з цим інтерфейсом — тег ставиться сам, LeadFields знаходить джерело за name(). Варіанти — лише для міста запиту.
 */
#[AutoconfigureTag('app.lead_option_source')]
interface LeadOptionSource
{
    /** Назва джерела в app.lead_fields (source: …): [a-z0-9_]. */
    public function name(): string;

    /**
     * Варіанти для міста в порядку показу. value — стабільний ключ (slug, id), label — підпис обома мовами
     * (ru порожнє = uk). Порожній список — поле у формі не показується.
     *
     * @return list<array{value: string, label: array{uk: string, ru: string}}>
     */
    public function options(ServiceCity $city): array;
}
