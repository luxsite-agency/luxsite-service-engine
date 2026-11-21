<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\LeadOptions;

use App\Entity\ServiceCity;
use App\Repository\ServiceTeamMemberRepository;
use App\ServiceSite\LeadOptionSource;

/**
 * source: team — активні учасники блоку «Команда» міста запиту (лікар, майстер), у порядку адмінки.
 * Підпис — «Ім'я (посада)»; value — id запису.
 */
final class TeamLeadOptions implements LeadOptionSource
{
    public function __construct(private readonly ServiceTeamMemberRepository $team)
    {
    }

    public function name(): string
    {
        return 'team';
    }

    public function options(ServiceCity $city): array
    {
        $out = [];
        foreach ($this->team->findActiveByCity($city) as $m) {
            $label = [];
            foreach (['uk', 'ru'] as $lang) {
                $name = trim((string) $m->loc('name', $lang));
                $role = trim((string) $m->loc('role', $lang));
                $label[$lang] = $role !== '' ? $name . ' (' . $role . ')' : $name;
            }
            $out[] = ['value' => (string) $m->getId(), 'label' => $label];
        }

        return $out;
    }
}
