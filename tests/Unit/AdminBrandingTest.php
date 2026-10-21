<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceSite;
use App\Repository\ServiceSiteRepository;
use App\Settings\AdminBranding;
use App\Settings\SiteSettingKeys as K;
use App\Tests\Support\SiteSettingsStubs;
use PHPUnit\Framework\TestCase;

/** Назва адмінки: назва сайту хоста адмінки; кілька сайтів без збігу хоста — «Сервісні сайти». */
final class AdminBrandingTest extends TestCase
{
    use SiteSettingsStubs;

    public function testSingleSiteGivesItsName(): void
    {
        $site = self::site(1, 'Example Clinic', 'example.test');
        $branding = new AdminBranding($this->repo([$site]), $this->settingsWith([]), 'admin.unrelated.test');

        self::assertSame('Example Clinic', $branding->title(), 'сайт один — його назва, навіть якщо хост адмінки інший');
        self::assertTrue($branding->singleSite());
        self::assertSame($site, $branding->site());
    }

    public function testSeveralSitesWithoutHostMatchKeepDefault(): void
    {
        $branding = new AdminBranding($this->repo([self::site(1, 'One', 'one.test'), self::site(2, 'Two', 'two.test')]), $this->settingsWith([]), 'service-sites.localhost');

        self::assertSame(AdminBranding::DEFAULT_TITLE, $branding->title());
        self::assertSame('Сервісні сайти', $branding->title());
        self::assertFalse($branding->singleSite());
        self::assertNull($branding->site());
    }

    public function testSeveralSitesPickTheOneOwningAdminHost(): void
    {
        $two = self::site(2, 'Two', 'two.test');
        $branding = new AdminBranding($this->repo([self::site(1, 'One', 'one.test'), $two]), $this->settingsWith([]), 'Admin.Two.Test');

        self::assertSame('Two', $branding->title());
        self::assertSame($two, $branding->site());
        self::assertFalse($branding->singleSite(), 'підпис «Сайт» — лише коли сайт у БД один');
    }

    public function testAdminTitleSettingOverridesSiteName(): void
    {
        $site = self::site(1, 'Example Clinic', 'example.test');
        $settings = $this->settingsWith([self::row($site, K::ADMIN_TITLE, ' Адмінка клініки ')]);

        self::assertSame('Адмінка клініки', (new AdminBranding($this->repo([$site]), $settings, 'admin.example.test'))->title());
    }

    public function testNoSitesKeepDefault(): void
    {
        self::assertSame(AdminBranding::DEFAULT_TITLE, (new AdminBranding($this->repo([]), $this->settingsWith([]), 'admin.example.test'))->title());
    }

    /** @param list<ServiceSite> $sites */
    private function repo(array $sites): ServiceSiteRepository
    {
        $repo = $this->createStub(ServiceSiteRepository::class);
        $repo->method('findBy')->willReturn($sites);

        return $repo;
    }
}
