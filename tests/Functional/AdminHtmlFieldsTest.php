<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\ServicePage;
use App\Entity\User;
use App\Tests\Support\TrilingualKernel;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTML-поля адмінки (glemanlas 23.11): збереження форми запису не переписує HTML полів, яких не чіпали.
 * Раніше TextEditorField (Trix) у браузері перетворював h2/h3/table/class/img на div/figure у всіх полях запису,
 * а TextareaType ще й обрізав пробіли й отримував CRLF від браузера. Тепер поле — textarea з HTML як є.
 */
final class AdminHtmlFieldsTest extends SeoTestCase
{
    /** Вміст, який Trix не зберігає: заголовки, класи, таблиця, списки, картинка; LF і хвостовий перенос рядка. */
    private const HTML = "<h2>A</h2>\n<div class=\"x\"><h3>B</h3><p>C</p></div>\n"
        . "<table class=\"price\"><tr><th>Ø</th><td>80–1000 мм</td></tr></table>\n<ol><li>1</li></ol><ul><li>2</li></ul>\n"
        . "<p><img src=\"/themes/gls/a.webp\" alt=\"a\" width=\"10\" height=\"10\"></p>\n";

    private int $pageId;

    protected static function getKernelClass(): string
    {
        return TrilingualKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->db();
        $db->update('service_city', ['intro_text_uk' => self::HTML, 'text2_uk' => self::HTML, 'text2_ru' => self::HTML, 'text2_en' => self::HTML, 'about_uk' => self::HTML], ['id' => $this->apexCityId]);
        $db->update('service_item', ['desc_uk' => self::HTML, 'desc_ru' => self::HTML, 'desc_en' => self::HTML, 'desc2_uk' => self::HTML], ['id' => $this->itemId]);
        $db->update('service_category', ['desc_uk' => self::HTML, 'desc3_uk' => self::HTML], ['id' => $this->categoryId]);
        $db->update('service_site', ['about_uk' => self::HTML, 'about_en' => self::HTML, 'privacy_uk' => self::HTML], ['id' => $this->siteId]);
        $page = (new ServicePage())->setSite($this->site())->setSlugUk('page-' . $this->s)->setTitleUk('Сторінка')
            ->setDescUk(self::HTML)->setDescEn(self::HTML)->setSlugEn('page-en-' . $this->s)->setTitleEn('Page');
        $this->em()->persist($page);
        $this->em()->flush();
        $this->pageId = (int) $page->getId();
        $this->em()->clear();
    }

    /** @return list<array{string, string, string, int, string, string, array<string, string>}> маршрут, форма, таблиця, id, поле, що міняємо, значення, HTML-поля (форма → колонка) */
    private function records(): array
    {
        return [
            ['service-city', 'ServiceCity', 'service_city', $this->apexCityId, 'introTitleEn', 'Chimneys in Kyiv',
                ['introTextUk' => 'intro_text_uk', 'text2Uk' => 'text2_uk', 'text2Ru' => 'text2_ru', 'text2En' => 'text2_en', 'aboutUk' => 'about_uk']],
            ['service-item', 'ServiceItem', 'service_item', $this->itemId, 'titleEn', 'Chimney',
                ['descUk' => 'desc_uk', 'descRu' => 'desc_ru', 'descEn' => 'desc_en', 'desc2Uk' => 'desc2_uk']],
            ['service-category', 'ServiceCategory', 'service_category', $this->categoryId, 'titleEn', 'Systems',
                ['descUk' => 'desc_uk', 'desc3Uk' => 'desc3_uk']],
            ['service-page', 'ServicePage', 'service_page', $this->pageId, 'titleEn', 'Edited page',
                ['descUk' => 'desc_uk', 'descEn' => 'desc_en']],
            ['service-site', 'ServiceSite', 'service_site', $this->siteId, 'aboutTitleEn', 'About us',
                ['aboutUk' => 'about_uk', 'aboutEn' => 'about_en', 'privacyUk' => 'privacy_uk']],
        ];
    }

    public function testHtmlFieldsAreRawTextareasNotTrix(): void
    {
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        foreach ($this->records() as [$route, $form, , $id, , , $html]) {
            $page = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/' . $route . '/' . $id . '/edit')->getContent();
            self::assertStringNotContainsString('<trix-editor', $page, $route . ': без візуального редактора, що переписує HTML');
            $fields = self::formFields($page, $form);
            foreach (array_keys($html) as $field) {
                self::assertMatchesRegularExpression('~<textarea[^>]*name="' . $form . '\[' . $field . '\]"~', $page, $route . ' ' . $field);
                self::assertSame(self::HTML, $fields[$form . '[' . $field . ']'] ?? null, $route . ' ' . $field . ': у формі HTML як у БД');
            }
        }
    }

    public function testSavingOtherFieldKeepsHtmlByteForByte(): void
    {
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        foreach ($this->records() as [$route, $form, $table, $id, $edit, $value, $html]) {
            $this->save($route, $form, $id, [$edit => $value]);
            $row = $this->db()->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$id]) ?: [];
            self::assertSame($value, $row[strtolower((string) preg_replace('/[A-Z]/', '_$0', $edit))] ?? null, $route . ': змінене поле збережено');
            foreach ($html as $field => $column) {
                self::assertSame(self::HTML, $row[$column], $route . ' ' . $column . ': не чіпали — байт-у-байт той самий');
            }
        }
    }

    public function testEditedHtmlIsStoredAsTyped(): void
    {
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        $typed = "<h2 class=\"gls-h\">Новий</h2>\n<table><tr><td>1</td></tr></table>\n<ol><li>x</li></ol>\n<img src=\"/a.webp\" alt=\"\">";
        foreach ($this->records() as [$route, $form, $table, $id, , , $html]) {
            $field = array_key_first($html);
            $this->save($route, $form, $id, [$field => $typed]);
            self::assertSame($typed, $this->db()->fetchOne('SELECT ' . $html[$field] . ' FROM ' . $table . ' WHERE id = ?', [$id]), $route . ' ' . $field);
        }
    }

    public function testAdminApiPatchStoresHtmlAsSent(): void
    {
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $this->moderatorEmail]);
        $token = static::getContainer()->get(AdminApiTokens::class)->issue($user, 'ci html', [ApiScope::ContentRead, ApiScope::ContentWrite], 1, $user)->plain;
        $sent = "<h2>API</h2>\r\n<table class=\"t\"><tr><td>1</td></tr></table>\n";
        foreach (['items/' . $this->itemId => 'service_item', 'pages/' . $this->pageId => 'service_page'] as $path => $table) {
            $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
            $request = Request::create('https://' . $this->adminHost . '/api/admin/v1/' . $this->slug . '/' . $path, 'PATCH', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json',
            ], (string) json_encode(['desc_uk' => $sent]));
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            self::assertSame(200, $response->getStatusCode(), $path . ': ' . mb_substr((string) $response->getContent(), 0, 300));
            $id = (int) substr($path, strpos($path, '/') + 1);
            self::assertSame($sent, $this->db()->fetchOne('SELECT desc_uk FROM ' . $table . ' WHERE id = ?', [$id]), $path);
            self::assertSame(self::HTML, $this->db()->fetchOne('SELECT desc_en FROM ' . $table . ' WHERE id = ?', [$id]), $path . ': інші поля не зачеплено');
        }
    }

    /**
     * Форма редагування як із браузера: усі поля, що віддала сторінка, з переносами рядків CRLF (так браузер кодує
     * textarea й інші значення форми), плюс зміни.
     *
     * @param array<string, string> $changes
     */
    private function save(string $route, string $form, int $id, array $changes): void
    {
        $url = 'https://' . $this->adminHost . '/admin/' . $route . '/' . $id . '/edit';
        $page = (string) $this->http('GET', $url)->getContent();
        $fields = self::formFields($page, $form) + self::selects($page, $form);
        foreach ($changes as $k => $v) {
            $fields[$form . '[' . $k . ']'] = $v;
        }
        $fields = array_map(static fn (string $v): string => (string) preg_replace("/\r?\n/", "\r\n", $v), $fields);
        $response = $this->http('POST', $url, self::nest($fields) + ['ea' => ['editForm' => ['btn' => 'saveAndReturn']]]);
        self::assertTrue($response->isRedirect(), $route . ': ' . $response->getStatusCode() . ' ' . mb_substr(strip_tags((string) $response->getContent()), 0, 500));
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
