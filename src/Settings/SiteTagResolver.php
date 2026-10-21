<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Пріоритети аналітики сайту (адмінка → Налаштування, 21.10.2026):
 *  1. Лічильник міста («Міста сервісів» → «Лічильник аналітики», GTM-/G-/AW-) — головний: якщо він є, GTM сайту в цьому
 *     місті не виводиться (два контейнери = подвійні перегляди). Порожньо в місті — GTM сайту.
 *  2. GA4 і Google Ads сайту — лише на сторінках без GTM (з GTM їх налаштовують усередині контейнера): одним gtag.js разом
 *     із лічильником міста G-/AW-, однаковий id — один раз.
 *  3. Clarity і код верифікації Google Search Console: налаштування сайту > параметр паку (app.clarity_id,
 *     app.site_verification) — так клієнт змінює їх без деплою, а пак лишається запасним значенням.
 *  4. Meta Pixel, Bing, банер згоди, свої HTML-коди — лише з налаштувань.
 */
final class SiteTagResolver implements ResetInterface
{
    /** @var array<int, SiteTags> memo на запит: id міста → теги */
    private array $memo = [];
    private readonly string $packClarityId;
    private readonly string $packVerification;

    /**
     * Параметри паку — як їх бачив шаблон до 21.10.2026 (Twig-глобали без типу): null чи число з YAML не мають ламати сайт.
     */
    public function __construct(
        private readonly SiteSettings $settings,
        #[Autowire('%app.clarity_id%')] mixed $packClarityId = '',
        #[Autowire('%app.site_verification%')] mixed $packVerification = '',
    ) {
        $this->packClarityId = \is_scalar($packClarityId) ? (string) $packClarityId : '';
        $this->packVerification = \is_scalar($packVerification) ? (string) $packVerification : '';
    }

    public function forCity(?ServiceCity $city): SiteTags
    {
        if (null === $city) {
            return new SiteTags(clarityId: $this->packClarity(), claritySource: null !== $this->packClarity() ? 'pack' : null, googleVerification: $this->packVerification);
        }
        $id = $city->getId();
        if (null !== $id && isset($this->memo[$id])) {
            return $this->memo[$id];
        }
        $tags = $this->resolve($city, $city->getSite());
        if (null !== $id) {
            $this->memo[$id] = $tags;
        }

        return $tags;
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    private function resolve(ServiceCity $city, ?ServiceSite $site): SiteTags
    {
        $s = fn (string $code): ?string => null === $site ? null : $this->settings->valid($site, $code);
        $siteGtm = $s(SiteSettingKeys::GTM_CONTAINER_ID);
        $siteGa4 = $s(SiteSettingKeys::GA4_MEASUREMENT_ID);
        $siteAds = $s(SiteSettingKeys::GOOGLE_ADS_ID);

        $kind = null;
        $gtmId = null;
        $gtagIds = [];
        $source = null;
        $cityKind = $city->getTagKind();
        if ('gtm' === $cityKind) {
            [$kind, $gtmId, $source] = ['gtm', (string) $city->getGtmId(), 'city'];
        } elseif ('gtag' === $cityKind) {
            [$kind, $source] = ['gtag', 'city'];
            $gtagIds = array_values(array_unique(array_filter([(string) $city->getGtmId(), $siteGa4, $siteAds])));
        } elseif (null !== $siteGtm) {
            [$kind, $gtmId, $source] = ['gtm', $siteGtm, 'site'];
        } elseif (null !== $siteGa4 || null !== $siteAds) {
            [$kind, $source] = ['gtag', 'site'];
            $gtagIds = array_values(array_filter([$siteGa4, $siteAds]));
        }

        $adsSendTo = null;
        $ga4LeadId = null;
        if ('gtag' === $kind) {
            $adsId = $siteAds ?? (str_starts_with((string) $city->getGtmId(), 'AW-') ? $city->getGtmId() : null);
            $label = $s(SiteSettingKeys::GOOGLE_ADS_LEAD_LABEL);
            $adsSendTo = null !== $adsId && null !== $label ? $adsId . '/' . $label : null;
            $ga4LeadId = $siteGa4;
        }

        $clarity = $s(SiteSettingKeys::CLARITY_ID);
        $claritySource = null !== $clarity ? 'settings' : (null !== $this->packClarity() ? 'pack' : null);

        return new SiteTags(
            kind: $kind,
            gtmId: $gtmId,
            gtagIds: $gtagIds,
            tagSource: $source,
            clarityId: $clarity ?? $this->packClarity(),
            claritySource: $claritySource,
            pixelId: $s(SiteSettingKeys::FB_PIXEL_ID),
            consentBanner: null !== $site && $this->settings->getBool($site, SiteSettingKeys::CONSENT_BANNER),
            googleVerification: $s(SiteSettingKeys::GOOGLE_SITE_VERIFICATION) ?? $this->packVerification,
            bingVerification: (string) $s(SiteSettingKeys::BING_SITE_VERIFICATION),
            htmlHead: null === $site ? '' : (string) $this->settings->get($site, SiteSettingKeys::HTML_HEAD),
            htmlBodyStart: null === $site ? '' : (string) $this->settings->get($site, SiteSettingKeys::HTML_BODY_START),
            htmlBodyEnd: null === $site ? '' : (string) $this->settings->get($site, SiteSettingKeys::HTML_BODY_END),
            adsSendTo: $adsSendTo,
            ga4LeadId: $ga4LeadId,
        );
    }

    /** Параметр паку як був: порожній рядок = нема (шаблон і раніше перевіряв `{% if clarity_id %}`). */
    private function packClarity(): ?string
    {
        return '' !== $this->packClarityId ? $this->packClarityId : null;
    }
}
