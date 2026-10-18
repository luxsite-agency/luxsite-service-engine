<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Crm;

/** Що вміє конектор: від цього залежать підказки в адмінці й рішення, чи взагалі ставити крон добору. */
final readonly class LeadCrmCapabilities
{
    public function __construct(
        /** Створює запис у CRM із заявки. */
        public bool $pushLead = false,
        /** Шукає наявний контакт за телефоном замість дубля. */
        public bool $dedupContact = false,
    ) {
    }
}
