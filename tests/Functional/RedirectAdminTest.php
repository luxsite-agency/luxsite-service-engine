<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ServiceSite\Redirect\NotFoundLog;
use App\ServiceSite\Redirect\RedirectCode;
use App\ServiceSite\Redirect\RedirectManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Адмінка менеджера редиректів (розділ «SEO», ROLE_MODERATOR): форма з нормалізацією, злиттям ланцюгів і відмовою на цикл
 * чи живу адресу; чужі домени — лише адміністратору; журнал 404 → «Створити редирект» з підставленими адресою, сайтом і
 * містом; очищення журналу лише POST з CSRF; CSV-імпорт зі звітом і експорт; «Перенести з паку».
 */
final class RedirectAdminTest extends SeoTestCase
{
    public function testSectionOpensForModeratorAndFormNormalizesMergesAndRejectsCycles(): void
    {
        $s = $this->s;
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $menu = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin')->getContent();
        self::assertStringContainsString('Редиректи', $menu);
        self::assertStringContainsString('Журнал 404', $menu);
        foreach (['/admin/service-redirect', '/admin/service-redirect/new', '/admin/service-redirect/import', '/admin/service-redirect/from-pack', '/admin/service-not-found', '/admin/service-not-found/clear'] as $path) {
            self::assertSame(200, $this->http('GET', 'https://' . $this->adminHost . $path)->getStatusCode(), $path);
        }
        static::getContainer()->get(RedirectManager::class)->save($this->site(), null, "/b-$s/", RedirectCode::Permanent, "/c-$s/");

        $r = $this->submitNew(['ServiceRedirect[source]' => "https://old.example/A-$s/?utm=1", 'ServiceRedirect[code]' => '301', 'ServiceRedirect[target]' => "/b-$s/"]);
        self::assertTrue($r->isRedirect(), 'збережено: ' . $r->getStatusCode());
        self::assertSame("/c-$s/", $this->target("/a-$s/"), 'джерело нормалізовано, ціль — кінцева');

        $r = $this->submitNew(['ServiceRedirect[source]' => "/c-$s/", 'ServiceRedirect[code]' => '301', 'ServiceRedirect[target]' => "/a-$s/"]);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('Цикл', (string) $r->getContent());

        $r = $this->submitNew(['ServiceRedirect[source]' => "/d-$s/", 'ServiceRedirect[code]' => '410', 'ServiceRedirect[target]' => 'буде відкинуто']);
        self::assertTrue($r->isRedirect());
        self::assertNull($this->target("/d-$s/"), '410 — без цілі');
        self::assertSame(410, $this->http('GET', 'https://' . $this->apex . "/d-$s/")->getStatusCode(), 'правило з адмінки діє на сайті');

        foreach (["/services/item-$s/", "/districts/district-$s/"] as $live) {
            $r = $this->submitNew(['ServiceRedirect[source]' => $live, 'ServiceRedirect[code]' => '301', 'ServiceRedirect[target]' => "/inshe-$s/"]);
            self::assertSame(422, $r->getStatusCode(), $live);
            self::assertStringContainsString('відкривається на сайті', (string) $r->getContent(), $live);
        }
        // рев'ю PR #18, п. 2: 410/404 на живу адресу — повідомлення у формі, не 500
        foreach (['410', '404'] as $code) {
            $r = $this->submitNew(['ServiceRedirect[source]' => "/services/item-$s/", 'ServiceRedirect[code]' => $code, 'ServiceRedirect[target]' => '']);
            self::assertSame(422, $r->getStatusCode(), $code . ' на живу адресу');
            self::assertStringContainsString('відкривається на сайті', (string) $r->getContent());
            self::assertFalse($this->db()->fetchOne('SELECT 1 FROM service_redirect WHERE site_id = ? AND source = ?', [$this->siteId, "/services/item-$s/"]), 'правило не записано');
        }
        $r = $this->submitNew(['ServiceRedirect[source]' => "/e-$s/", 'ServiceRedirect[code]' => '301', 'ServiceRedirect[target]' => '']);
        self::assertSame(422, $r->getStatusCode(), '301 без цілі');
        self::assertStringContainsString('вкажіть ціль', (string) $r->getContent());

        $r = $this->submitNew(['ServiceRedirect[source]' => "/districts/district-$s/", 'ServiceRedirect[code]' => '410', 'ServiceRedirect[target]' => '', 'ServiceRedirect[city]' => (string) $this->branchCityId]);
        self::assertTrue($r->isRedirect(), 'район апекса на хості філії — 404, правило міста можна');
        self::assertSame(RedirectCode::Gone, static::getContainer()->get(RedirectManager::class)->find($this->siteId, $this->branchCityId, "/districts/district-$s/")['code'] ?? null);

        $r = $this->submitNew(['ServiceRedirect[source]' => "/out-$s/", 'ServiceRedirect[code]' => '301', 'ServiceRedirect[target]' => 'https://evil.example/']);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('адміністратор', (string) $r->getContent());
        self::assertTrue($this->submitNew(['ServiceRedirect[source]' => "/out-$s/", 'ServiceRedirect[code]' => '301', 'ServiceRedirect[target]' => 'https://' . $this->branch . "/in-$s/"])->isRedirect());
        self::assertSame("/in-$s/", $this->target("/out-$s/"), 'свій хост — шлях');

        $this->http('GET', 'https://' . $this->adminHost . '/logout'); // сесія модератора завершена — вхід адміністратором
        $this->login($this->adminHost, $this->adminEmail, $this->password);
        self::assertTrue($this->submitNew(['ServiceRedirect[source]' => "/ext-$s/", 'ServiceRedirect[code]' => '301', 'ServiceRedirect[target]' => 'https://partner.example/x'])->isRedirect(), 'адміністратору чужий домен можна');
        self::assertSame('https://partner.example/x', $this->target("/ext-$s/"));
    }

    public function testCreateRedirectFromNotFoundLogAndClearNeedsPost(): void
    {
        $s = $this->s;
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        static::getContainer()->get(NotFoundLog::class)->record($this->siteId, $this->branchCityId, "/lost-$s/", null);
        $id = (int) $this->db()->fetchOne('SELECT id FROM service_not_found WHERE site_id = ? AND path = ?', [$this->siteId, "/lost-$s/"]);

        $index = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-not-found')->getContent();
        self::assertStringContainsString('Створити редирект', $index);
        self::assertStringContainsString("/lost-$s/", $index);
        $r = $this->http('GET', 'https://' . $this->adminHost . "/admin/service-not-found/$id/create-redirect");
        self::assertTrue($r->isRedirect());
        $form = (string) $this->http('GET', (string) $r->headers->get('Location'))->getContent();
        self::assertStringContainsString('value="/lost-' . $s . '/"', $form, 'адресу підставлено');
        // select-и (сайт, місто) formFields не читає — звіряємо обрані option і передаємо явно
        self::assertSame(1, preg_match('/<select[^>]*name="ServiceRedirect\[site\]".*?<option value="(\d+)" selected/s', $form, $m), 'сайт підставлено');
        self::assertSame((string) $this->siteId, $m[1]);
        self::assertSame(1, preg_match('/<select[^>]*name="ServiceRedirect\[city\]".*?<option value="(\d+)" selected/s', $form, $m), 'місто підставлено');
        self::assertSame((string) $this->branchCityId, $m[1]);
        $fields = self::formFields($form, 'ServiceRedirect') + ['ServiceRedirect[site]' => (string) $this->siteId, 'ServiceRedirect[city]' => (string) $this->branchCityId];

        $r = $this->http('POST', (string) $r->headers->get('Location'), self::nest(array_replace($fields, ['ServiceRedirect[code]' => '301', 'ServiceRedirect[target]' => "/found-$s/"])) + ['ea' => ['newForm' => ['btn' => 'saveAndReturn']]]);
        self::assertTrue($r->isRedirect(), (string) $r->getStatusCode());
        self::assertSame("/found-$s/", $this->target("/lost-$s/", $this->branchCityId));
        self::assertFalse($this->logged("/lost-$s/"), 'з журналу знято');

        static::getContainer()->get(NotFoundLog::class)->record($this->siteId, $this->apexCityId, "/another-$s/", null);
        $page = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-not-found/clear?site=' . $this->siteId)->getContent();
        self::assertTrue($this->logged("/another-$s/"), 'GET нічого не видаляє');
        self::assertSame(1, preg_match('/name="token" value="([^"]+)"/', $page, $m));
        $r = $this->http('POST', 'https://' . $this->adminHost . '/admin/service-not-found/clear', ['token' => 'wrong', 'site' => (string) $this->siteId]);
        self::assertTrue($r->isRedirect());
        self::assertTrue($this->logged("/another-$s/"), 'хибний CSRF — нічого не видалено');
        $r = $this->http('POST', 'https://' . $this->adminHost . '/admin/service-not-found/clear', ['token' => html_entity_decode($m[1]), 'site' => (string) $this->siteId]);
        self::assertTrue($r->isRedirect());
        self::assertFalse($this->logged("/another-$s/"));
    }

    public function testCsvImportShowsReportExportDownloadsAndPackActionRuns(): void
    {
        $s = $this->s;
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $file = tempnam(sys_get_temp_dir(), 'csv') . '.csv';
        file_put_contents($file, "source;code;target;city\n/i1-$s/;301;/i2-$s/\n/i3-$s/;301;\n/i4-$s/;410;;branch\n/services/item-$s/;301;/x-$s/\n");

        $page = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-redirect/import')->getContent();
        $fields = self::formFields($page, 'form');
        $fields['form[site]'] = (string) $this->siteId;
        $upload = new UploadedFile($file, 'redirects.csv', 'text/csv', null, true);
        $r = $this->http('POST', 'https://' . $this->adminHost . '/admin/service-redirect/import', self::nest($fields), [], ['form' => ['file' => $upload]]);
        unlink($file);
        self::assertSame(200, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertSame(1, preg_match('/data-created>(\d+)</', $html, $m));
        self::assertSame('2', $m[1], $html);
        self::assertSame(1, preg_match('/data-errors>(\d+)</', $html, $m));
        self::assertSame('2', $m[1], 'нема цілі + жива адреса');
        self::assertStringContainsString('data-error-line="3"', $html);
        self::assertStringContainsString('data-error-line="5"', $html);
        self::assertSame(RedirectCode::Gone, static::getContainer()->get(RedirectManager::class)->find($this->siteId, $this->branchCityId, "/i4-$s/")['code'] ?? null, 'правило міста з CSV');

        $r = $this->http('GET', 'https://' . $this->adminHost . '/admin/service-redirect/export?site=' . $this->siteId);
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('text/csv; charset=UTF-8', $r->headers->get('content-type'));
        ob_start();
        $r->sendContent();
        $csv = (string) ob_get_clean();
        self::assertStringContainsString("/i1-$s/;301;/i2-$s/;;manual;0;", $csv);
        self::assertStringContainsString("/i4-$s/;410;;branch;manual;0;", $csv);

        $page = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-redirect/from-pack?site=' . $this->siteId)->getContent();
        self::assertSame(1, preg_match('/name="token" value="([^"]+)"/', $page, $m));
        self::assertStringContainsString('data-pack-redirects>1<', $page, 'пак тестового середовища: одне правило (when@test у services.yaml)');
        $r = $this->http('POST', 'https://' . $this->adminHost . '/admin/service-redirect/from-pack', ['token' => html_entity_decode($m[1]), 'site' => (string) $this->siteId]);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringContainsString('створено 1, оновлено 0, пропущено 0, з помилками 0', (string) $r->getContent());
        self::assertSame('/ci-pack-new/', $this->target('/ci-pack-old/'), 'однорівневий ключ паку перенесено');
        $r = $this->http('POST', 'https://' . $this->adminHost . '/admin/service-redirect/from-pack', ['token' => html_entity_decode($m[1]), 'site' => (string) $this->siteId]);
        self::assertStringContainsString('створено 0, оновлено ', (string) $r->getContent(), 'повторно — ідемпотентно');
    }

    /** @param array<string, string> $values */
    private function submitNew(array $values): Response
    {
        $url = 'https://' . $this->adminHost . '/admin/service-redirect/new?site=' . $this->siteId;
        $form = (string) $this->http('GET', $url)->getContent();
        // select-и formFields не читає: сайт — завжди, місто — порожньо (усі міста), якщо тест не задав
        $fields = array_replace(self::formFields($form, 'ServiceRedirect'), ['ServiceRedirect[site]' => (string) $this->siteId, 'ServiceRedirect[city]' => ''], $values);

        return $this->http('POST', $url, self::nest($fields) + ['ea' => ['newForm' => ['btn' => 'saveAndReturn']]]);
    }
}
