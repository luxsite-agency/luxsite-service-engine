<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceLead;
use Symfony\Component\HttpFoundation\Request;

/**
 * Джерело заявки з прихованих полів форми.
 *
 * Мітки збирає JS у base.html.twig і кладе в localStorage при першому переході
 * з реклами чи пошуку, а форма підставляє їх у момент відправки. Так джерело
 * доїжджає навіть тоді, коли людина зайшла з оголошення, походила по сайту й
 * залишила заявку вже на іншій сторінці.
 *
 * Дані з форми - тому обрізаємо по довжині колонок і не довіряємо вмісту.
 */
final class LeadAttribution
{
    /** поле форми => [сеттер, довжина колонки] */
    private const FIELDS = [
        'utm_source' => ['setUtmSource', 128],
        'utm_medium' => ['setUtmMedium', 128],
        'utm_campaign' => ['setUtmCampaign', 191],
        'utm_content' => ['setUtmContent', 191],
        'utm_term' => ['setUtmTerm', 191],
        'click_id' => ['setClickId', 191],
        'referrer' => ['setReferrer', 512],
        'landing_url' => ['setLandingUrl', 512],
    ];

    public function apply(Request $request, ServiceLead $lead): void
    {
        foreach (self::FIELDS as $field => [$setter, $limit]) {
            $value = trim((string) $request->request->get($field, ''));
            if ($value === '') {
                continue;
            }

            $lead->{$setter}(mb_substr($value, 0, $limit));
        }
    }

    /** Рядок для листа й для CRM: «google / cpc / spring-sale». */
    public static function summary(ServiceLead $lead): string
    {
        $parts = array_filter([
            $lead->getUtmSource(),
            $lead->getUtmMedium(),
            $lead->getUtmCampaign(),
        ]);
        if ($parts !== []) {
            return implode(' / ', $parts);
        }

        $referrer = $lead->getReferrer();
        if ($referrer !== null && $referrer !== '') {
            return 'перехід з ' . (parse_url($referrer, PHP_URL_HOST) ?: $referrer);
        }

        return 'прямий захід';
    }
}
