<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCategory;
use App\Entity\ServiceCategoryCity;
use App\Entity\ServiceDistrict;
use App\Entity\ServiceItem;
use App\Entity\ServiceItemCity;
use App\Entity\ServicePage;
use App\Entity\ServicePrice;
use App\ServiceSite\Redirect\SiteLiveUrls;
use App\Tests\Support\EnglishCatalogKernel;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class EnglishCatalogTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return EnglishCatalogKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        $site = $this->site()->setLocales(['en']);
        $city = $this->city($this->apexCityId)->setNameNomEn('New York');
        $category = $em->find(ServiceCategory::class, $this->categoryId)
            ->setSlugEn('moving')->setTitleEn('Moving services')->setMetaTitleEn('Moving services in New York')
            ->setMetaDescEn('English category description')->setLeadEn('Plan your move');
        $item = $em->find(ServiceItem::class, $this->itemId)
            ->setSlugEn('local-movers')->setTitleEn('Local movers')->setMetaTitleEn('Local movers in New York')
            ->setMetaDescEn('English service description')->setDescEn('<p>Move with our team.</p>')
            ->setFaqUk([['q' => 'Українське питання', 'a' => 'Українська відповідь']])
            ->setFaqEn([['q' => 'Can you pack my belongings?', 'a' => 'Yes, packing is available.']]);
        $em->find(ServiceDistrict::class, $this->districtId)->setSlugEn('brooklyn')->setNameEn('Brooklyn')->setGroupEn('Brooklyn')->setIsPrimary(true)
            ->setTitleEn('Movers in Brooklyn')->setMetaTitleEn('Brooklyn moving services')->setMetaDescEn('English district description')
            // сусідні місцевості шаблон виводить усередині опису району
            ->setDescEn('<p>Moving in Brooklyn.</p>');
        $em->persist((new ServiceDistrict())->setCity($city)->setSlugUk('neighborhood')->setSlugEn('williamsburg')
            ->setNameUk('Мікрорайон')->setNameEn('Williamsburg')->setGroupEn('Brooklyn'));
        $em->persist((new ServiceItemCity())->setItem($item)->setCity($city)->setLeadUk('Місцевий український лід'));
        $em->persist((new ServiceCategoryCity())->setCategory($category)->setCity($city)->setDescUk('Місцевий український опис'));
        $em->persist((new ServicePrice())->setItem($item)->setCity($city)->setNameUk('Українська ціна')
            ->setPrice('150')->setNameEn('Packing')->setDescEn('Boxes and wrapping')->setUnitEn('box')->setPriceTextEn('Contact us'));
        $em->persist((new ServicePage())->setSite($site)->setSlugUk('vakansii')->setSlugEn('careers')
            ->setTitleUk('Вакансії')->setTitleEn('Careers')->setH1En('Join our moving team')
            ->setMetaTitleEn('Careers in New York')->setMetaDescEn('English careers description')->setDescEn('<p>Apply today.</p>'));
        $em->persist((new ServicePage())->setSite($site)->setSlugUk('uk-only')->setTitleUk('Секретний український заголовок')
            ->setH1Uk('Секретний український H1')->setDescUk('Секретний український опис'));
        $em->flush();
        $em->clear();
    }

    public function testEnglishCatalogPagesSeoFaqAndSitemap(): void
    {
        foreach ([
            '/moving/' => ['Moving services', 'Moving services in New York', 'English category description'],
            '/local-movers/' => ['Local movers', 'Local movers in New York', 'English service description'],
            '/districts/brooklyn/' => ['Movers in Brooklyn', 'Brooklyn moving services', 'English district description'],
            '/careers/' => ['Join our moving team', 'Careers in New York', 'English careers description'],
        ] as $path => [$heading, $title, $description]) {
            $response = $this->http('GET', 'https://' . $this->apex . $path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $html = (string) $response->getContent();
            self::assertMatchesRegularExpression('~<h1[^>]*>\s*' . preg_quote($heading, '~') . '\s*</h1>~', $html);
            self::assertStringContainsString('<title>' . $title . '</title>', $html);
            self::assertMatchesRegularExpression('~<meta name="description" content="' . preg_quote($description, '~') . '"~', $html);
            self::assertStringNotContainsString('/en/', $html);
            self::assertStringNotContainsString('Місцевий український', $html, 'en overrides never fall back to uk');
        }
        $html = (string) $this->http('GET', 'https://' . $this->apex . '/local-movers/')->getContent();
        self::assertStringContainsString('Packing', $html);
        // країна й валюта schema.org з паку (app.schema_country, app.currency), не жорстко UA/UAH
        self::assertStringContainsString('"priceCurrency":"USD"', str_replace(' ', '', $html));
        // видиму валюту задають переклади паку (catalog.uah); тут — лише розмітка schema.org
        self::assertStringNotContainsString('"priceCurrency":"UAH"', str_replace(' ', '', $html));
        self::assertStringNotContainsString('"addressCountry":"UA"', str_replace(' ', '', $html));
        preg_match_all('~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $scripts);
        $faq = null;
        foreach ($scripts[1] as $json) {
            $ld = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (($ld['@type'] ?? '') === 'FAQPage') {
                $faq = $ld;
            }
        }
        self::assertNotNull($faq);
        self::assertSame('Can you pack my belongings?', $faq['mainEntity'][0]['name']);
        self::assertSame('Yes, packing is available.', $faq['mainEntity'][0]['acceptedAnswer']['text']);

        $sitemap = (string) $this->http('GET', 'https://' . $this->apex . '/sitemap.xml')->getContent();
        foreach (['/moving/', '/local-movers/', '/districts/brooklyn/', '/careers/'] as $path) {
            self::assertStringContainsString('https://' . $this->apex . $path, $sitemap);
        }
        self::assertStringNotContainsString('/en/', $sitemap);
        $districtHtml = (string) $this->http('GET', 'https://' . $this->apex . '/districts/brooklyn/')->getContent();
        self::assertStringContainsString('/districts/williamsburg/', $districtHtml, 'groupEn connects English-only neighborhoods');
    }

    public function testSlugFallbackAndMissingTranslationsNeverLeakUkrainian(): void
    {
        foreach (['/hidden-' . $this->s . '/', '/uk-only/'] as $path) {
            $response = $this->http('GET', 'https://' . $this->apex . $path);
            self::assertSame(200, $response->getStatusCode());
            $html = (string) $response->getContent();
            self::assertStringNotContainsString('Лише апекс', $html);
            self::assertStringNotContainsString('Секретний український', $html);
        }
        $item = $this->em()->find(ServiceItem::class, $this->itemId);
        $item->setFaqEn(null);
        $this->em()->flush();
        $html = (string) $this->http('GET', 'https://' . $this->apex . '/local-movers/')->getContent();
        self::assertStringNotContainsString('FAQPage', $html, 'English FAQ does not fall back to Ukrainian');
    }

    public function testRepositoriesUseEnglishSlugsAndFallbackOnlyWithoutEnglishSlug(): void
    {
        $em = $this->em();
        $site = $this->site();
        $category = $em->find(ServiceCategory::class, $this->categoryId);
        $items = $em->getRepository(ServiceItem::class);
        self::assertSame($this->itemId, $items->findOneActiveBySlug($category, 'local-movers', 'en')?->getId());
        self::assertSame($this->itemId, $items->findOneActiveBySiteAndSlug($site, 'local-movers', 'en')?->getId());
        self::assertNull($items->findOneActiveBySiteAndSlug($site, 'item-' . $this->s, 'en'));
        self::assertSame($this->hiddenItemId, $items->findOneActiveBySiteAndSlug($site, 'hidden-' . $this->s, 'en')?->getId());
        self::assertSame($this->categoryId, $em->getRepository(ServiceCategory::class)->findOneActiveBySlug($site, 'moving', 'en')?->getId());
        self::assertNull($em->getRepository(ServiceCategory::class)->findOneActiveBySlug($site, 'cat-' . $this->s, 'en'));
        self::assertSame($this->districtId, $em->getRepository(ServiceDistrict::class)->findOneActiveBySlug($this->city($this->apexCityId), 'brooklyn', 'en')?->getId());
        self::assertNull($em->getRepository(ServiceDistrict::class)->findOneActiveBySlug($this->city($this->apexCityId), 'district-' . $this->s, 'en'));
        self::assertNotNull($em->getRepository(ServicePage::class)->findOneActiveByPath($site, 'careers', 'en'));
        self::assertNull($em->getRepository(ServicePage::class)->findOneActiveByPath($site, 'vakansii', 'en'));
        self::assertNotNull($em->getRepository(ServicePage::class)->findOneActiveByPath($site, 'uk-only', 'en'));
        self::assertTrue(static::getContainer()->get(SiteLiveUrls::class)->isLive($site, $this->city($this->apexCityId), '/local-movers/'));

        $category->setSlugEn(null);
        $em->find(ServiceDistrict::class, $this->districtId)->setSlugEn(null);
        $em->flush();
        self::assertSame($this->categoryId, $em->getRepository(ServiceCategory::class)->findOneActiveBySlug($site, 'cat-' . $this->s, 'en')?->getId());
        self::assertSame($this->districtId, $em->getRepository(ServiceDistrict::class)->findOneActiveBySlug($this->city($this->apexCityId), 'district-' . $this->s, 'en')?->getId());
    }

    public function testEnglishSlugChangesKeepAutomaticRedirects(): void
    {
        $this->em()->find(ServiceItem::class, $this->itemId)->setSlugEn('new-york-movers');
        $this->em()->find(ServiceCategory::class, $this->categoryId)->setSlugEn('moving-services');
        $this->em()->flush();
        $this->em()->clear();
        foreach (['/local-movers/' => '/new-york-movers/', '/moving/' => '/moving-services/'] as $old => $new) {
            $response = $this->http('GET', 'https://' . $this->apex . $old);
            self::assertSame(301, $response->getStatusCode(), $old);
            self::assertStringEndsWith($new, (string) $response->headers->get('Location'));
            self::assertSame(200, $this->http('GET', 'https://' . $this->apex . $new)->getStatusCode());
        }
    }

    public function testModeratorSeesEnglishFieldsAndRootConflictIsAFormError(): void
    {
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        foreach (['service-item' => 'ServiceItem', 'service-category' => 'ServiceCategory', 'service-district' => 'ServiceDistrict',
            'service-page' => 'ServicePage', 'service-item-city' => 'ServiceItemCity', 'service-category-city' => 'ServiceCategoryCity',
            'service-price' => 'ServicePrice', 'service-team-member' => 'ServiceTeamMember', 'service-review' => 'ServiceReview', 'service-work' => 'ServiceWork'] as $route => $form) {
            $response = $this->http('GET', 'https://' . $this->adminHost . '/admin/' . $route . '/new');
            self::assertSame(200, $response->getStatusCode(), $route);
            self::assertMatchesRegularExpression('~name="' . $form . '\[[a-zA-Z0-9]+En\]~', (string) $response->getContent(), $route);
        }
        $url = 'https://' . $this->adminHost . '/admin/service-page/new';
        $html = (string) $this->http('GET', $url)->getContent();
        $fields = array_replace(self::formFields($html, 'ServicePage'), [
            'ServicePage[site]' => (string) $this->siteId, 'ServicePage[parent]' => '',
            'ServicePage[titleUk]' => '', 'ServicePage[slugUk]' => '',
            'ServicePage[titleEn]' => 'English only', 'ServicePage[slugEn]' => 'contacts',
        ]);
        foreach (['contacts' => 'зайнята фіксованим розділом', 'local-movers' => 'уже зайнята',
            'careers' => 'Слаг en уже використовується', 'Bad_Slug' => 'лише латиниця'] as $slug => $error) {
            $fields['ServicePage[slugEn]'] = $slug;
            $response = $this->http('POST', $url, self::nest($fields) + ['ea' => ['newForm' => ['btn' => 'saveAndReturn']]]);
            self::assertSame(422, $response->getStatusCode(), $slug);
            self::assertStringContainsString($error, (string) $response->getContent(), $slug);
            self::assertStringNotContainsString('Заголовок uk обовʼязковий', (string) $response->getContent());
            self::assertStringNotContainsString('Слаг uk обовʼязковий', (string) $response->getContent());
        }

        $page = (new ServicePage())->setSite($this->site())->setSlugEn('english-only')->setTitleEn('English only');
        self::assertCount(0, static::getContainer()->get(ValidatorInterface::class)->validate($page));
        $fields['ServicePage[slugEn]'] = 'english-only';
        $response = $this->http('POST', $url, self::nest($fields) + ['ea' => ['newForm' => ['btn' => 'saveAndReturn']]]);
        self::assertTrue($response->isRedirect(), 'English-only page can be saved without uk text');
    }
}
