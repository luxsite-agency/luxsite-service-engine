<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\LeadOptions;

use App\Entity\ServiceCity;
use App\Repository\ServiceItemRepository;
use App\ServiceSite\LeadOptionSource;

/**
 * source: services — активні послуги, що надаються в місті запиту (ServiceItem.hiddenCities), у порядку каталогу.
 * value — slug_uk послуги (на сторінці послуги форма вибирає її сама).
 */
final class ServicesLeadOptions implements LeadOptionSource
{
    public function __construct(private readonly ServiceItemRepository $items)
    {
    }

    public function name(): string
    {
        return 'services';
    }

    public function options(ServiceCity $city): array
    {
        $site = $city->getSite();
        if ($site === null) {
            return [];
        }

        $out = [];
        foreach ($this->items->findActiveBySite($site, $city) as $item) {
            // англійська назва — лише там, де в сутності є колонка titleEn (англійський каталог)
            $en = method_exists($item, 'getTitleEn') ? trim((string) $item->getTitleEn()) : '';
            $uk = trim($item->getTitleUk()) ?: $en;
            $out[] = ['value' => $item->getSlugUk(), 'label' => ['uk' => $uk, 'ru' => trim((string) $item->getTitleRu()) ?: $uk, 'en' => $en !== '' ? $en : $uk]];
        }

        return $out;
    }
}
