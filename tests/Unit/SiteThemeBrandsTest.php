<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use App\ServiceSite\BrandSettings;
use App\ServiceSite\SiteTheme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Тема на бренд (app.brands, 22.10.2026): два бренди одним сервісом (як smoke одним ядром), без app.brands — як було. */
final class SiteThemeBrandsTest extends TestCase
{
    public function testBrandThemesResolvePerMainRequest(): void
    {
        $stack = new RequestStack();
        $theme = new SiteTheme(['colors' => ['accent' => '#111111']], '', [
            'alpha' => ['theme' => ['colors' => ['accent' => '#aa0000'], 'logo' => 'themes/alpha/logo.svg']],
            'beta' => ['theme' => ['key' => 'beta-custom']],
        ], $stack);

        self::assertSame('#111111', $theme->colors()['accent'], 'без запиту — тема розгортання');
        self::assertSame('', $theme->get('key'));

        $this->main($stack, 'alpha');
        self::assertSame('#aa0000', $theme->colors()['accent']);
        self::assertSame('alpha', $theme->get('key'), 'key бренду за замовчуванням — slug');
        self::assertSame('/themes/alpha/logo.svg', $theme->url('logo'));
        self::assertSame('#faa125', $theme->colors()['accent2'], 'решта кольорів — з теми розгортання/дефолтів');

        $this->main($stack, 'beta');
        self::assertSame('#111111', $theme->colors()['accent'], 'бренд без кольорів — кольори розгортання');
        self::assertSame('beta-custom', $theme->get('key'));

        $this->main($stack, 'gamma');
        self::assertSame('', $theme->get('key'), 'бренд без запису в app.brands — тема розгортання');
    }

    public function testWithoutBrandsBehavesAsBefore(): void
    {
        $theme = new SiteTheme(['key' => 'site', 'colors' => ['dark' => '#000000']], '');
        self::assertSame('site', $theme->get('key'));
        self::assertSame('#000000', $theme->colors()['dark']);
        self::assertSame('#f98111', $theme->colors()['accent']);
    }

    /** Спільний набір шаблонів (24.10.2026): app.brands.<slug>.templates — тека в templates/site/brands/, інакше slug */
    public function testSharedTemplatesDir(): void
    {
        $brands = ['eplus' => ['templates' => 'news'], 'trysail' => []];
        new SiteTheme([], '', $brands);
        $settings = new BrandSettings($brands);
        self::assertSame('news', $settings->templatesDir((new ServiceSite())->setSlug('eplus')));
        self::assertSame('trysail', $settings->templatesDir((new ServiceSite())->setSlug('trysail')));
        self::assertSame('other', $settings->templatesDir((new ServiceSite())->setSlug('other')));
        $this->expectException(\InvalidArgumentException::class);
        new SiteTheme([], '', ['eplus' => ['templates' => '../news']]);
    }

    public function testUnknownBrandKeysRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteTheme([], '', ['alpha' => ['theme' => ['colours' => []]]]);
    }

    private function main(RequestStack $stack, string $slug): void
    {
        while ($stack->pop() !== null) {
        }
        $r = Request::create('https://' . $slug . '.example/');
        $r->attributes->set(ServiceHostSubscriber::ATTR_SITE, (new ServiceSite())->setSlug($slug));
        $stack->push($r);
    }
}
