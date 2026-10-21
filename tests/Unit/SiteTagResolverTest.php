<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Settings\SiteSettingKeys as K;
use App\Settings\SiteTagResolver;
use App\Tests\Support\SiteSettingsStubs;
use PHPUnit\Framework\TestCase;

/** Пріоритети аналітики: лічильник міста > GTM сайту; GA4/Ads сайту — лише без GTM; Clarity й GSC — адмінка > пак. */
final class SiteTagResolverTest extends TestCase
{
    use SiteSettingsStubs;

    public function testWithoutSettingsSameAsCityFieldsAndPack(): void
    {
        $site = self::site(1);
        $resolver = new SiteTagResolver($this->settingsWith([]), 'packclar01', 'pack-verification');

        $gtm = $resolver->forCity(self::city($site, 1, 'a.test', 'GTM-CITY111'));
        self::assertSame(['gtm', 'GTM-CITY111', 'city'], [$gtm->kind, $gtm->gtmId, $gtm->tagSource]);
        self::assertSame('packclar01', $gtm->clarityId);
        self::assertSame('pack', $gtm->claritySource);
        self::assertSame('pack-verification', $gtm->googleVerification);
        self::assertFalse($gtm->runtime(), 'без нових налаштувань — жодного нового скрипта');
        self::assertSame('', $gtm->pixelJs());

        $gtag = $resolver->forCity(self::city($site, 2, 'b.test', 'G-CITY22222'));
        self::assertSame(['gtag', ['G-CITY22222']], [$gtag->kind, $gtag->gtagIds]);
        self::assertNull($gtag->adsSendTo);
        self::assertNull($gtag->ga4LeadId);

        $none = $resolver->forCity(self::city($site, 3, 'c.test'));
        self::assertNull($none->kind);
        self::assertSame([], $none->gtagIds);

        $noPack = (new SiteTagResolver($this->settingsWith([])))->forCity(self::city($site, 4, 'd.test'));
        self::assertNull($noPack->clarityId, 'порожній app.clarity_id = Clarity нема');
        self::assertSame('', $noPack->googleVerification);
    }

    public function testCityCounterWinsOverSiteGtm(): void
    {
        $site = self::site(1);
        $resolver = new SiteTagResolver($this->settingsWith([self::row($site, K::GTM_CONTAINER_ID, 'GTM-SITE111')]));

        $own = $resolver->forCity(self::city($site, 1, 'a.test', 'GTM-CITY111'));
        self::assertSame(['GTM-CITY111', 'city'], [$own->gtmId, $own->tagSource]);

        $empty = $resolver->forCity(self::city($site, 2, 'b.test'));
        self::assertSame(['gtm', 'GTM-SITE111', 'site'], [$empty->kind, $empty->gtmId, $empty->tagSource]);

        $gtag = $resolver->forCity(self::city($site, 3, 'c.test', 'AW-123456789'));
        self::assertSame(['gtag', ['AW-123456789'], 'city'], [$gtag->kind, $gtag->gtagIds, $gtag->tagSource], 'місто з G-/AW- теж не отримує GTM сайту');
    }

    public function testSiteGa4AndAdsOnlyWithoutGtm(): void
    {
        $site = self::site(1);
        $rows = [
            self::row($site, K::GA4_MEASUREMENT_ID, 'G-SITE22222'),
            self::row($site, K::GOOGLE_ADS_ID, 'AW-123456789'),
            self::row($site, K::GOOGLE_ADS_LEAD_LABEL, 'LeadLabel_1'),
        ];
        $resolver = new SiteTagResolver($this->settingsWith($rows));

        $direct = $resolver->forCity(self::city($site, 1, 'a.test'));
        self::assertSame(['gtag', ['G-SITE22222', 'AW-123456789'], 'site'], [$direct->kind, $direct->gtagIds, $direct->tagSource]);
        self::assertSame('AW-123456789/LeadLabel_1', $direct->adsSendTo);
        self::assertSame('G-SITE22222', $direct->ga4LeadId);
        self::assertTrue($direct->runtime());
        self::assertSame('G-SITE22222', $direct->gtagLoaderId());

        $withCityGtag = $resolver->forCity(self::city($site, 2, 'b.test', 'AW-123456789'));
        self::assertSame(['AW-123456789', 'G-SITE22222'], $withCityGtag->gtagIds, 'лічильник міста перший, однаковий id — один раз');

        $withGtm = $resolver->forCity(self::city($site, 3, 'c.test', 'GTM-CITY111'));
        self::assertSame(['gtm', []], [$withGtm->kind, $withGtm->gtagIds], 'з GTM GA4/Ads сайту не виводяться');
        self::assertNull($withGtm->adsSendTo);
        self::assertNull($withGtm->ga4LeadId);
    }

    public function testCityAdsIdIsUsedForConversionLabel(): void
    {
        $site = self::site(1);
        $resolver = new SiteTagResolver($this->settingsWith([self::row($site, K::GOOGLE_ADS_LEAD_LABEL, 'LeadLabel_1')]));

        self::assertSame('AW-987654321/LeadLabel_1', $resolver->forCity(self::city($site, 1, 'a.test', 'AW-987654321'))->adsSendTo);
        self::assertNull($resolver->forCity(self::city($site, 2, 'b.test', 'G-CITY22222'))->adsSendTo, 'без id Google Ads мітка нічого не дає');
    }

    public function testSettingsOverridePackAndInvalidValuesAreIgnored(): void
    {
        $site = self::site(1);
        $other = self::site(2);
        $rows = [
            self::row($site, K::CLARITY_ID, 'adminclar1'),
            self::row($site, K::GOOGLE_SITE_VERIFICATION, 'admin-verification'),
            self::row($site, K::BING_SITE_VERIFICATION, '0123456789ABCDEF'),
            self::row($site, K::FB_PIXEL_ID, '1234567890'),
            self::row($site, K::CONSENT_BANNER, '1'),
            self::row($site, K::HTML_HEAD, '<meta name="x" content="y">'),
            self::row($site, K::GTM_CONTAINER_ID, 'not-a-gtm'), // формат змінився / SQL руками — не виводиться
            self::row($other, K::GTM_CONTAINER_ID, 'GTM-OTHER11'),
        ];
        $resolver = new SiteTagResolver($this->settingsWith($rows), 'packclar01', 'pack-verification');
        $tags = $resolver->forCity(self::city($site, 1, 'a.test'));

        self::assertSame(['adminclar1', 'settings'], [$tags->clarityId, $tags->claritySource]);
        self::assertSame('admin-verification', $tags->googleVerification);
        self::assertSame('0123456789ABCDEF', $tags->bingVerification);
        self::assertNull($tags->kind, 'невалідний GTM і GTM іншого сайту не виводяться');
        self::assertSame('<meta name="x" content="y">', $tags->htmlHead);
        self::assertTrue($tags->consentBanner);
        self::assertStringContainsString("fbq('init','1234567890')", $tags->pixelJs());
        self::assertStringStartsWith("svTags.run('consent'", $tags->pixelJs(), 'з банером піксель лише після згоди');
        self::assertStringContainsString("gtag('consent','default'", $tags->runtimeJs());
    }

    public function testPixelWithoutConsentStartsAtOnce(): void
    {
        $site = self::site(1);
        $tags = (new SiteTagResolver($this->settingsWith([self::row($site, K::FB_PIXEL_ID, '1234567890')])))->forCity(self::city($site, 1, 'a.test'));

        self::assertStringStartsWith('!function(f,b,e,v,n,t,s)', $tags->pixelJs());
        self::assertStringNotContainsString('consent', $tags->runtimeJs());
    }
}
