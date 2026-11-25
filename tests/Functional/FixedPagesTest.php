<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FixedPagesKernel;

final class FixedPagesTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return FixedPagesKernel::class;
    }

    public function testDisabledAndRedirectedPagesAndLinks(): void
    {
        foreach (['', '/ru'] as $prefix) {
            foreach (['/faq/', '/reviews/', '/terms-of-service/'] as $path) {
                self::assertSame(404, $this->http('GET', 'https://' . $this->apex . $prefix . $path)->getStatusCode());
            }
            foreach (['/contacts/' => '/our-office/', '/privacy-policy/' => '/legal/'] as $path => $target) {
                $response = $this->http('GET', 'https://' . $this->apex . $prefix . $path);
                self::assertSame(301, $response->getStatusCode());
                self::assertSame($prefix . $target, $response->headers->get('Location'));
            }
            $response = $this->http('GET', 'https://' . $this->apex . $prefix . '/');
            self::assertSame(200, $response->getStatusCode());
            $html = (string) $response->getContent();
            foreach (['faq', 'contacts', 'reviews', 'privacy-policy', 'terms-of-service'] as $path) {
                self::assertStringNotContainsString('href="' . $prefix . '/' . $path . '/"', $html);
            }
            self::assertStringContainsString('href="' . $prefix . '/our-office/"', $html);
            self::assertStringContainsString('href="' . $prefix . '/legal/"', $html);
        }
        $xml = (string) $this->http('GET', 'https://' . $this->apex . '/sitemap.xml')->getContent();
        foreach (['faq', 'contacts', 'reviews', 'privacy-policy', 'terms-of-service'] as $path) {
            self::assertStringNotContainsString('/' . $path . '/', $xml);
        }
        // sv_legal_path (паки, #71) теж зважає на app.fixed_pages: вимкнена — null, адреса — та сама, що fixed_page_path
        $request = \Symfony\Component\HttpFoundation\Request::create('https://' . $this->apex . '/');
        $request->attributes->set('service_city', $this->city($this->apexCityId));
        $request->attributes->set(\App\EventSubscriber\ServiceHostSubscriber::ATTR_SITE, $this->site());
        $request->setLocale('uk');
        static::getContainer()->get('request_stack')->push($request);
        try {
            $ext = static::getContainer()->get(\App\Twig\ServiceSiteExtension::class);
            self::assertSame(['/legal/', null], [$ext->legalPath('privacy'), $ext->legalPath('terms')]);
        } finally {
            static::getContainer()->get('request_stack')->pop();
        }
        $map = static::getContainer()->get(\App\ServiceSite\HtmlSitemap::class)->build($this->site(), $this->city($this->apexCityId), 'uk');
        self::assertEmpty(array_intersect(array_column($map['fixed'], 'url'), ['/faq/', '/reviews/', '/contacts/', '/our-office/', '/legal/']));
    }
}
