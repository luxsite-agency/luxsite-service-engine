<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCategory;
use App\Entity\ServiceItem;
use App\Entity\ServiceLead;
use App\Entity\ServicePage;
use App\Tests\Support\TrilingualKernel;

/**
 * Розгортання [uk, ru, en] (glemanlas, 23.11.2026): en — третя мова під /en/. Запис без англійських полів англійською не існує:
 * /en/<слаг>/ — 404 (не українській текст під en-адресою), без hreflang="en", без EN у перемикачі й sitemap.
 */
final class TrilingualLocaleTest extends SeoTestCase
{
    private int $pageId;

    protected static function getKernelClass(): string
    {
        return TrilingualKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $em = $this->em();
        $em->find(ServiceCategory::class, $this->categoryId)
            ->setTitleRu('Категория')
            ->setSlugEn('chimneys')->setTitleEn('Chimney systems');
        $em->find(ServiceItem::class, $this->itemId)
            ->setTitleRu('Услуга')
            ->setSlugEn('ceramic-chimneys')->setTitleEn('Ceramic chimney systems')->setNameEn('Ceramic chimneys')
            ->setMetaTitleEn('Ceramic chimneys GLS')->setMetaDescEn('English item description')->setDescEn('<p>English text.</p>');
        // hidden-<s>: послуга без англійських полів (на апексі надається)
        $page = (new ServicePage())->setSite($this->site())->setSlugUk('proektuvalnykam')->setTitleUk('Проєктувальникам')
            ->setH1Uk('Проєктувальникам H1')->setDescUk('<p>Український текст сторінки.</p>')
            ->setSlugEn('designers')->setTitleEn('For designers')->setH1En('For designers H1')->setDescEn('<p>English page text.</p>')
            ->setMetaTitleEn('Designers GLS')->setMetaDescEn('English page description');
        $em->persist($page);
        $em->persist((new ServicePage())->setSite($this->site())->setSlugUk('tilky-uk')->setTitleUk('Лише українською')
            ->setDescUk('<p>Секретний український текст.</p>'));
        $em->flush();
        $this->pageId = (int) $page->getId();
        $em->clear();
    }

    private function get(string $path): array
    {
        $r = $this->http('GET', 'https://' . $this->apex . $path);

        return [$r->getStatusCode(), (string) $r->getContent()];
    }

    public function testEnglishItemPage(): void
    {
        [$status, $html] = $this->get('/en/ceramic-chimneys/');
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('~<h1[^>]*>\s*Ceramic chimney systems\s*</h1>~', $html);
        self::assertStringContainsString('<html lang="en"', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://' . $this->apex . '/en/ceramic-chimneys/">', $html);
        self::assertSame(0, substr_count($html, '<!--'), 'en-сторінка без HTML-коментарів');
        self::assertStringNotContainsString('Послуга', strip_tags(preg_replace('~<script.*?</script>~s', '', $html) ?? ''));
    }

    public function testUkrainianPageHasAllAlternatesAndSwitcher(): void
    {
        [$status, $html] = $this->get('/item-' . $this->s . '/');
        self::assertSame(200, $status);
        foreach (['uk-UA' => '/item-' . $this->s . '/', 'ru-UA' => '/ru/item-' . $this->s . '/', 'en' => '/en/ceramic-chimneys/', 'x-default' => '/item-' . $this->s . '/'] as $lang => $path) {
            self::assertStringContainsString('<link rel="alternate" hreflang="' . $lang . '" href="https://' . $this->apex . $path . '">', $html, $lang);
        }
        self::assertSame(1, preg_match_all('~<span class="lang-switch">(.*?)</span>~s', $html, $m));
        $switch = $m[1][0];
        foreach (['UA', 'RU', 'EN'] as $label) {
            self::assertStringContainsString('>' . $label . '</a>', $switch);
        }
        self::assertMatchesRegularExpression('~class="active">UA</a>~', $switch, 'поточна мова виділена');
        self::assertStringContainsString('href="https://' . $this->apex . '/en/ceramic-chimneys/"', $switch);
        self::assertStringNotContainsString('<img', $switch);
    }

    public function testItemWithoutEnglishFieldsIsNotEnglish(): void
    {
        self::assertSame(404, $this->get('/en/hidden-' . $this->s . '/')[0], 'en-адреса з uk-слагом без перекладу');
        [$status, $html] = $this->get('/hidden-' . $this->s . '/');
        self::assertSame(200, $status);
        self::assertStringNotContainsString('hreflang="en"', $html);
        self::assertStringContainsString('hreflang="ru-UA"', $html);
        self::assertSame(1, preg_match('~<span class="lang-switch">(.*?)</span>~s', $html, $m));
        self::assertStringNotContainsString('>EN</a>', $m[1]);
        self::assertStringContainsString('>RU</a>', $m[1]);
    }

    public function testCategoryWithoutEnglishTitleIs404AndWithIt200(): void
    {
        [$status, $html] = $this->get('/en/chimneys/');
        self::assertSame(200, $status);
        self::assertStringContainsString('Chimney systems', $html);
        self::assertStringContainsString('/en/ceramic-chimneys/', $html, 'послуга з перекладом у списку');
        self::assertStringNotContainsString('/en/hidden-' . $this->s . '/', $html, 'послуги без перекладу в en-списку нема');
        $this->em()->find(ServiceCategory::class, $this->categoryId)->setTitleEn(null);
        $this->em()->flush();
        self::assertSame(404, $this->get('/en/chimneys/')[0]);
    }

    public function testEnglishPages(): void
    {
        [$status, $html] = $this->get('/en/designers/');
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('~<h1[^>]*>\s*For designers H1\s*</h1>~', $html);
        self::assertStringContainsString('English page text.', $html);
        self::assertStringContainsString('<html lang="en"', $html);
        self::assertSame(0, substr_count($html, '<!--'));
        [, $uk] = $this->get('/proektuvalnykam/');
        self::assertStringContainsString('hreflang="en" href="https://' . $this->apex . '/en/designers/"', $uk);

        self::assertSame(404, $this->get('/en/tilky-uk/')[0]);
        [$status, $html] = $this->get('/tilky-uk/');
        self::assertSame(200, $status);
        self::assertStringNotContainsString('hreflang="en"', $html);
    }

    public function testSitemapListsOnlyTranslatedEnglishUrls(): void
    {
        [$status, $xml] = $this->get('/sitemap.xml');
        self::assertSame(200, $status);
        self::assertStringContainsString('https://' . $this->apex . '/en/ceramic-chimneys/', $xml);
        self::assertStringContainsString('https://' . $this->apex . '/en/chimneys/', $xml);
        self::assertStringContainsString('https://' . $this->apex . '/en/designers/', $xml);
        self::assertStringContainsString('https://' . $this->apex . '/en/', $xml, 'головна en');
        self::assertStringNotContainsString('/en/hidden-' . $this->s . '/', $xml);
        self::assertStringNotContainsString('/en/tilky-uk/', $xml);
        self::assertStringNotContainsString('/en/item-' . $this->s . '/', $xml);
    }

    public function testLeadFromEnglishPage(): void
    {
        $page = 'https://' . $this->apex . '/en/ceramic-chimneys/';
        $html = (string) $this->http('GET', $page)->getContent();
        self::assertSame(1, preg_match('~name="_token" value="([^"]+)"~', $html, $m), 'форма заявки з токеном на en-сторінці');
        $phone = '+380 67 ' . random_int(100, 999) . ' 11 22';
        $response = $this->http('POST', 'https://' . $this->apex . '/lead', ['_token' => html_entity_decode($m[1]), 'phone' => $phone, 'name' => 'John'], ['HTTP_REFERER' => $page]);
        self::assertTrue($response->isRedirect(), (string) $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertMatchesRegularExpression('~^(https://' . preg_quote($this->apex, '~') . ')?/en/[^?]+~', $location);
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['phone' => $phone]);
        self::assertNotNull($lead);
        self::assertSame('en', $lead->getLocale());
        $thanks = $this->http('GET', str_starts_with($location, 'http') ? $location : 'https://' . $this->apex . $location);
        self::assertSame(200, $thanks->getStatusCode());
        self::assertStringContainsString('<html lang="en"', (string) $thanks->getContent());
    }

    public function testEnglishHomeAndInterface(): void
    {
        [$status, $html] = $this->get('/en/');
        self::assertSame(200, $status);
        self::assertStringContainsString('<html lang="en"', $html);
        self::assertStringContainsString('hreflang="x-default"', $html);
        self::assertSame(0, substr_count($html, '<!--'));
    }

    public function testLegalPagesInEnglishOnlyWithBrandText(): void
    {
        // типовий текст рушія лише uk/ru: en без тексту бренду — 404, посилання футера й згоди ведуть на uk
        self::assertSame(404, $this->get('/en/privacy-policy/')[0]);
        [$status, $uk] = $this->get('/privacy-policy/');
        self::assertSame(200, $status);
        self::assertStringNotContainsString('hreflang="en"', $uk);
        [, $home] = $this->get('/en/');
        self::assertStringContainsString('href="/privacy-policy/"', $home);
        self::assertStringNotContainsString('href="/en/privacy-policy/"', $home);
        self::assertStringNotContainsString('/en/privacy-policy/', $this->get('/sitemap.xml')[1]);
        self::assertStringContainsString('/ru/privacy-policy/', $this->get('/sitemap.xml')[1]);

        $this->site()->setPrivacyEn('<p>English privacy policy.</p>');
        $this->em()->flush();
        [$status, $html] = $this->get('/en/privacy-policy/');
        self::assertSame(200, $status);
        self::assertStringContainsString('English privacy policy.', $html);
        self::assertStringNotContainsString('Політика', $html);
        self::assertStringContainsString('href="/en/privacy-policy/"', $this->get('/en/')[1]);
        self::assertStringContainsString('/en/privacy-policy/', $this->get('/sitemap.xml')[1]);
    }

    public function testAdminSavesEnglishFieldsOfItemCategoryAndPage(): void
    {
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        foreach ([
            ['service-item', 'ServiceItem', $this->itemId, ['titleEn' => 'Edited chimney', 'slugEn' => 'edited-chimney', 'metaDescEn' => 'Edited meta']],
            ['service-category', 'ServiceCategory', $this->categoryId, ['titleEn' => 'Edited systems', 'slugEn' => 'edited-systems']],
            ['service-page', 'ServicePage', $this->pageId, ['titleEn' => 'Edited page', 'h1En' => 'Edited H1', 'slugEn' => 'edited-page']],
        ] as [$route, $form, $id, $values]) {
            $url = 'https://' . $this->adminHost . '/admin/' . $route . '/' . $id . '/edit';
            $html = (string) $this->http('GET', $url)->getContent();
            self::assertMatchesRegularExpression('~name="' . $form . '\\[titleEn\\]~', $html, $route . ': поле en поруч з uk/ru');
            $fields = self::formFields($html, $form) + self::selects($html, $form);
            foreach ($values as $k => $v) {
                $fields[$form . '[' . $k . ']'] = $v;
            }
            $response = $this->http('POST', $url, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]]);
            self::assertTrue($response->isRedirect(), $route . ': ' . $response->getStatusCode() . ' ' . mb_substr(strip_tags((string) $response->getContent()), 0, 300));
        }
        $this->em()->clear();
        $item = $this->em()->find(ServiceItem::class, $this->itemId);
        self::assertSame(['Edited chimney', 'edited-chimney', 'Edited meta'], [$item->getTitleEn(), $item->getSlugEn(), $item->getMetaDescEn()]);
        self::assertSame('Послуга', $item->getTitleUk(), 'uk не зачеплено');
        self::assertSame('Edited systems', $this->em()->find(ServiceCategory::class, $this->categoryId)->getTitleEn());
        self::assertSame('Edited H1', $this->em()->find(ServicePage::class, $this->pageId)->getH1En());
        self::assertSame(200, $this->get('/en/edited-chimney/')[0]);
    }

    /** Обрані значення <select> форми (formFields бере лише input/textarea). @return array<string, string> */
    private static function selects(string $html, string $formName): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $out = [];
        foreach ((new \DOMXPath($doc))->query('//select[@name]') ?: [] as $select) {
            \assert($select instanceof \DOMElement);
            $name = $select->getAttribute('name');
            if (!str_starts_with($name, $formName . '[') || str_ends_with($name, '[]')) {
                continue;
            }
            foreach ((new \DOMXPath($doc))->query('.//option[@selected]', $select) ?: [] as $opt) {
                \assert($opt instanceof \DOMElement);
                $out[$name] = $opt->getAttribute('value');
            }
        }

        return $out;
    }
}
