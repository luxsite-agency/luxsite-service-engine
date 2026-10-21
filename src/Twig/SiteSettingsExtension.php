<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ServiceCity;
use App\Settings\AdminBranding;
use App\Settings\SiteTagResolver;
use App\Settings\SiteTags;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Налаштування сайту в шаблонах (адмінка → Налаштування, 21.10.2026). Окреме розширення, а не ServiceSiteExtension,
 * щоб не перетинатися з паралельними гілками. Лише публічні значення (id лічильників, коди верифікації, свої HTML-коди):
 * секрети сюди не потрапляють за побудовою.
 *  - site_tags(city) — що виводити в <head>/<body> (SiteTagResolver: пріоритети міста, сайту й паку);
 *  - site_lead_js(city, lead_type, events, host) — виклики gtag/fbq для /thanks, якщо їх просять налаштування; '' — нічого;
 *  - admin_title() — назва адмінки (AdminBranding).
 */
final class SiteSettingsExtension extends AbstractExtension
{
    private const int JSON = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly SiteTagResolver $tags,
        private readonly AdminBranding $branding,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_tags', $this->siteTags(...)),
            new TwigFunction('site_lead_js', $this->leadJs(...), ['is_safe' => ['html']]),
            new TwigFunction('admin_title', $this->branding->title(...)),
        ];
    }

    public function siteTags(?ServiceCity $city): SiteTags
    {
        return $this->tags->forCity($city);
    }

    /**
     * JS для сторінки «Дякуємо» (без тегу script): конверсія Google Ads (send_to з налаштувань), події заявки в GA4
     * сайту, якщо він виведений через gtag (з GTM ціль робить контейнер із dataLayer), і Lead для Meta Pixel. Виклики
     * стають у чергу svTags і виконуються, коли тег стартував (зокрема з app.analytics_defer і після згоди на cookie).
     *
     * @param list<string> $events app.lead_events
     */
    public function leadJs(?ServiceCity $city, string $leadType, array $events, string $host): string
    {
        $t = $this->tags->forCity($city);
        $js = [];
        if (null !== $t->ga4LeadId) {
            foreach ($events as $event) {
                $params = ['send_to' => $t->ga4LeadId, 'lead_type' => $leadType, 'city' => $city?->name('uk') ?? '', 'site_host' => $host];
                $js[] = "svTags.run('gtag',function(){gtag('event'," . json_encode((string) $event, self::JSON) . ',' . json_encode($params, self::JSON) . ');});';
            }
        }
        if (null !== $t->adsSendTo) {
            $js[] = "svTags.run('gtag',function(){gtag('event','conversion'," . json_encode(['send_to' => $t->adsSendTo], self::JSON) . ');});';
        }
        if (null !== $t->pixelId) {
            $js[] = "svTags.run('fbq',function(){fbq('track','Lead');});";
        }

        return implode("\n", $js);
    }
}
