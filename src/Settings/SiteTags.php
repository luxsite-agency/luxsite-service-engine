<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

/**
 * Що виводити на сторінці міста (base.html.twig, `site_tags(city)`): уже розв'язані пріоритети, лише публічні id (жодних
 * секретів — об'єкт бачить будь-який шаблон, зокрема живий дизайн під токеном). Сайт без налаштувань дає рівно те, що
 * давали поля міста й параметри паку до 21.10.2026 (kind = city.tagKind, gtmId/gtagIds = [city.gtmId], clarityId =
 * app.clarity_id, googleVerification = app.site_verification) — HTML тих самих сторінок не змінюється.
 */
final readonly class SiteTags
{
    /**
     * @param 'gtm'|'gtag'|null $kind
     * @param list<string>      $gtagIds перший — у src gtag.js, далі — gtag('config') для кожного
     * @param 'city'|'site'|null $tagSource звідки лічильник: поле міста чи GTM / GA4 / Ads із налаштувань сайту
     * @param 'settings'|'pack'|null $claritySource
     */
    public function __construct(
        public ?string $kind = null,
        public ?string $gtmId = null,
        public array $gtagIds = [],
        public ?string $tagSource = null,
        public ?string $clarityId = null,
        public ?string $claritySource = null,
        public ?string $pixelId = null,
        public bool $consentBanner = false,
        public string $googleVerification = '',
        public string $bingVerification = '',
        public string $htmlHead = '',
        public string $htmlBodyStart = '',
        public string $htmlBodyEnd = '',
        /** send_to конверсії Google Ads на /thanks (лише сторінки з gtag, без GTM) */
        public ?string $adsSendTo = null,
        /** GA4 із налаштувань, виведений через gtag: на /thanks події заявки йдуть ще й gtag('event') з send_to */
        public ?string $ga4LeadId = null,
    ) {
    }

    /** Потрібна черга svTags (згода, піксель, конверсії на /thanks) — лише коли є щось із нових налаштувань. */
    public function runtime(): bool
    {
        return $this->consentBanner || null !== $this->pixelId || null !== $this->adsSendTo || null !== $this->ga4LeadId;
    }

    /** id для src gtag.js (перший у списку). */
    public function gtagLoaderId(): ?string
    {
        return $this->gtagIds[0] ?? null;
    }

    /**
     * Скрипт у <head> перед тегами (лише якщо runtime()): черга svTags — run(k, f) виконує f, коли подія k настала
     * (gtag — gtag.js сконфігуровано, fbq — піксель ініціалізовано, consent — згода на cookie), go(k) — подія настала.
     * З банером згоди — Consent Mode v2: gtag('consent','default') до GTM/gtag за cookie sv_consent (granted|denied,
     * без cookie — denied). Стан читається в браузері, тож HTML однаковий для всіх (кеш сторінок не заважає).
     */
    public function runtimeJs(): string
    {
        $js = 'window.svTags=window.svTags||{q:[],ok:{},run:function(k,f){if(this.ok[k]){f();}else{this.q.push([k,f]);}},'
            . 'go:function(k){if(this.ok[k]){return;}this.ok[k]=1;var q=this.q;this.q=[];for(var i=0;i<q.length;i++){if(q[i][0]===k){q[i][1]();}else{this.q.push(q[i]);}}}};';
        if ($this->consentBanner) {
            $js .= 'window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};'
                . "(function(){var g=/(?:^|;\\s*)sv_consent=granted/.test(document.cookie)?'granted':'denied';"
                . "gtag('consent','default',{ad_storage:g,ad_user_data:g,ad_personalization:g,analytics_storage:g,wait_for_update:500});"
                . "if(g==='granted'){svTags.go('consent');}})();";
        }

        return $js;
    }

    /**
     * Meta Pixel: офіційний сніпет, PageView, далі svTags.go('fbq') (Lead на /thanks чекає в черзі). З банером згоди —
     * лише після «Прийняти» (без згоди fbevents.js не вантажиться). '' — пікселя нема.
     */
    public function pixelJs(): string
    {
        if (null === $this->pixelId) {
            return '';
        }
        $js = "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};"
            . "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;"
            . "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');"
            . "fbq('init','" . $this->pixelId . "');fbq('track','PageView');svTags.go('fbq');";

        return $this->consentBanner ? "svTags.run('consent',function(){" . $js . '});' : $js;
    }
}
