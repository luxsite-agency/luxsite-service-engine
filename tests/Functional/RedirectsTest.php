<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCategory;
use App\Entity\ServiceDistrict;
use App\Entity\ServiceItem;
use App\Entity\ServicePost;
use App\ServiceSite\LegacyUrls;
use App\ServiceSite\Redirect\InvalidRedirect;
use App\ServiceSite\Redirect\NotFoundLog;
use App\ServiceSite\Redirect\PackRedirects;
use App\ServiceSite\Redirect\RedirectCode;
use App\ServiceSite\Redirect\RedirectCsv;
use App\ServiceSite\Redirect\RedirectKind;
use App\ServiceSite\Redirect\RedirectManager;

/**
 * Менеджер редиректів на сайті (модель shop-engine E3, 23.10.2026): правило спрацьовує лише на 404 хоста міста, правило
 * міста має перевагу над правилом сайту, 301/302 з перенесенням query за правилами паку, 410 і 404 — сторінки помилок,
 * без правила — журнал 404 (без query/IP/UA, без ботів); нормалізована жива адреса — 301 без журналу. Авто-301: зміна
 * slug послуги, поста (сайт) і району (місто) веде зі старої адреси на нову, повернення slug знімає правило. Інваріанти
 * менеджера: злиття ланцюгів, цикли, живі адреси, свій хост, CSV, перенесення з паку, ліміт журналу.
 */
final class RedirectsTest extends SeoTestCase
{
    public function testRulesApplyOnlyToNotFoundUrls(): void
    {
        $s = $this->s;
        $redirects = static::getContainer()->get(RedirectManager::class);
        $site = $this->site();
        $branch = $this->city($this->branchCityId);
        $item = "/services/item-$s/";
        $redirects->save($site, null, "/old-$s/", RedirectCode::Permanent, $item);
        $redirects->save($site, null, "/ru/temp-$s/", RedirectCode::Temporary, "/ru/elsewhere-$s/");
        $redirects->save($site, null, "/gone-$s/", RedirectCode::Gone, null);
        $redirects->save($site, null, "/known-404-$s/", RedirectCode::NotFound, null);
        $redirects->save($site, null, "/noslash-$s", RedirectCode::Permanent, "/services/");
        $redirects->save($site, null, "/scoped-$s/", RedirectCode::Permanent, "/a-$s/");
        $redirects->save($site, $branch, "/scoped-$s/", RedirectCode::Permanent, "/b-$s/");
        // правило з живої адреси (записане повз перевірку) не перекриває сторінку
        $this->db()->insert('service_redirect', ['site_id' => $this->siteId, 'city_key' => 0, 'source' => $item, 'target' => "/never-$s/", 'target_path' => "/never-$s/", 'code' => 301, 'kind' => 'manual', 'hits' => 0, 'created_at' => date('Y-m-d H:i:s')]);

        $r = $this->http('GET', 'https://' . $this->apex . "/OLD-$s/?utm_source=mail&task=no");
        self::assertSame(301, $r->getStatusCode());
        self::assertSame('https://' . $this->apex . $item . '?utm_source=mail', $r->headers->get('Location'), 'регістр нормалізовано, трекінг перенесено, зайвий query зрізано за правилами паку');
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'), 'правила змінні — 301 не кешується');
        self::assertSame(200, $this->http('GET', (string) $r->headers->get('Location'))->getStatusCode());

        $r = $this->http('GET', 'https://' . $this->apex . "/ru/temp-$s/");
        self::assertSame(302, $r->getStatusCode());
        self::assertSame('https://' . $this->apex . "/ru/elsewhere-$s/", $r->headers->get('Location'));

        $r = $this->http('GET', 'https://' . $this->apex . "/gone-$s/");
        self::assertSame(410, $r->getStatusCode());
        // у тестовому середовищі debug=true і ErrorController показує виняток; сам шаблон — рендером (uk/ru за шляхом)
        $twig = static::getContainer()->get('twig');
        self::assertStringContainsString('<h1>410</h1>', $twig->render('@Twig/Exception/error410.html.twig'), 'сторінка error410 рушія через обгортку bundles/');
        self::assertStringContainsString('Цієї сторінки більше немає', $twig->render('services/error410.html.twig'));

        self::assertSame(404, $this->http('GET', 'https://' . $this->apex . "/known-404-$s/")->getStatusCode());
        self::assertSame(200, $this->http('GET', 'https://' . $this->apex . $item)->getStatusCode(), 'живу послугу не переадресовано');

        // правило без слеша спрацьовує і після гігієни слеша паку
        $r = $this->http('GET', 'https://' . $this->apex . "/noslash-$s");
        self::assertSame(301, $r->getStatusCode());
        self::assertSame('https://' . $this->apex . "/noslash-$s/", $r->headers->get('Location'), 'спершу слеш (пак)');
        $r = $this->http('GET', 'https://' . $this->apex . "/noslash-$s/");
        self::assertSame(301, $r->getStatusCode());
        self::assertSame('https://' . $this->apex . '/services/', $r->headers->get('Location'), 'потім правило з БД за альтернативною формою');

        // правило міста має перевагу над правилом сайту лише на своєму хості
        self::assertSame('https://' . $this->branch . "/b-$s/", $this->http('GET', 'https://' . $this->branch . "/scoped-$s/")->headers->get('Location'));
        self::assertSame('https://' . $this->apex . "/a-$s/", $this->http('GET', 'https://' . $this->apex . "/scoped-$s/")->headers->get('Location'));

        self::assertNotSame(301, $this->http('POST', 'https://' . $this->apex . "/old-$s/")->getStatusCode(), 'лише GET/HEAD');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT hits FROM service_redirect WHERE site_id = ? AND source = ?', [$this->siteId, "/old-$s/"]), 'лічильник після відповіді; POST не рахується');
        self::assertNotNull($this->db()->fetchOne('SELECT last_hit_at FROM service_redirect WHERE site_id = ? AND source = ?', [$this->siteId, "/gone-$s/"]));
        self::assertFalse($this->logged("/gone-$s/"), 'адреси з правилом — не в журналі');
        self::assertFalse($this->logged("/known-404-$s/"));

        self::assertNotSame(301, $this->http('GET', 'https://' . $this->adminHost . "/old-$s/")->getStatusCode(), 'хост адмінки — без редиректів');
    }

    public function testWrongCaseOfLiveUrlRedirectsWithoutLogging(): void
    {
        $s = $this->s;
        $r = $this->http('GET', 'https://' . $this->apex . "/Services/ITEM-$s/?utm_medium=x");
        self::assertSame(301, $r->getStatusCode());
        self::assertSame('https://' . $this->apex . "/services/item-$s/?utm_medium=x", $r->headers->get('Location'));
        self::assertSame(301, $this->http('GET', 'https://' . $this->branch . "/Districts/Branch-District-$s/")->getStatusCode(), 'район філії на її хості');
        self::assertFalse($this->logged("/services/item-$s/"), 'живі адреси — не в журналі');

        self::assertSame(404, $this->http('GET', 'https://' . $this->apex . "/No-Such-$s/")->getStatusCode(), 'мертва адреса в іншому регістрі — 404 і журнал');
        self::assertTrue($this->logged("/no-such-$s/", $this->apexCityId));

        // район лише в апексі: на хості філії 301 туди (CrossCityDistrictSubscriber), не журнал
        $r = $this->http('GET', 'https://' . $this->branch . "/districts/district-$s/");
        self::assertSame(301, $r->getStatusCode());
        self::assertSame('https://' . $this->apex . "/districts/district-$s/", $r->headers->get('Location'));
        self::assertFalse($this->logged("/districts/district-$s/"), 'міжміський 301 району — без журналу');
    }

    public function testUnknownUrlsGoToNotFoundLogPerCity(): void
    {
        $s = $this->s;
        self::assertSame(404, $this->http('GET', 'https://' . $this->apex . "/Missing-$s/?email=a@b.c", [], ['HTTP_REFERER' => 'https://www.google.com/url?q=secret'])->getStatusCode());
        $this->http('GET', 'https://' . $this->apex . "/missing-$s/");
        $this->http('GET', 'https://' . $this->branch . "/missing-$s/");
        $row = $this->db()->fetchAssociative('SELECT hits, referer FROM service_not_found WHERE site_id = ? AND city_id = ? AND path = ?', [$this->siteId, $this->apexCityId, "/missing-$s/"]);
        self::assertNotFalse($row);
        self::assertSame(2, (int) $row['hits']);
        self::assertSame('https://www.google.com/url', $row['referer'], 'query referer відкинуто');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT hits FROM service_not_found WHERE site_id = ? AND city_id = ? AND path = ?', [$this->siteId, $this->branchCityId, "/missing-$s/"]), 'на хост міста окремий рядок');

        foreach (['/wp-login.php', '/.env', "/admin/x-$s", "/api/admin/v1/x-$s", '/media/cache/resolve/x.jpg', '/favicon.ico'] as $probe) {
            $this->http('GET', 'https://' . $this->apex . $probe);
            self::assertFalse($this->logged(strtolower($probe)), $probe . ' — не журналюється');
        }
    }

    public function testSlugChangesWriteAutoRedirectsAndRevertRemovesThem(): void
    {
        $s = $this->s;
        $em = $this->em();
        $item = $em->find(ServiceItem::class, $this->itemId);
        self::assertNotNull($item);
        $item->setSlugUk("item-new-$s");
        $em->flush();
        self::assertSame("/services/item-new-$s/", $this->target("/services/item-$s/"));
        self::assertSame("/ru/services/item-new-$s/", $this->target("/ru/services/item-$s/"), 'ru без власного slug іде за uk');
        self::assertSame(RedirectKind::Auto->value, $this->db()->fetchOne('SELECT kind FROM service_redirect WHERE site_id = ? AND source = ?', [$this->siteId, "/services/item-$s/"]));
        $r = $this->http('GET', 'https://' . $this->branch . "/services/item-$s/");
        self::assertSame(301, $r->getStatusCode(), 'правило сайту діє на всіх хостах');
        self::assertSame('https://' . $this->branch . "/services/item-new-$s/", $r->headers->get('Location'));
        self::assertSame(200, $this->http('GET', 'https://' . $this->branch . "/services/item-new-$s/")->getStatusCode());

        $item = $em->find(ServiceItem::class, $this->itemId); // запити через ядро скидають EM (kernel.reset) — беремо запис знову
        self::assertNotNull($item);
        $item->setSlugRu("tovar-$s"); // лише ru: uk-адреса не змінилась
        $em->flush();
        self::assertSame("/ru/services/tovar-$s/", $this->target("/ru/services/item-new-$s/"));
        self::assertSame("/ru/services/tovar-$s/", $this->target("/ru/services/item-$s/"), 'старіший ланцюг злито');
        self::assertNull($this->target("/services/item-new-$s/"));

        $item->setTitleUk('Інша назва'); // інші поля правил не пишуть
        $em->flush();
        self::assertSame("/services/item-new-$s/", $this->target("/services/item-$s/"));

        $item = $em->find(ServiceItem::class, $this->itemId);
        self::assertNotNull($item);
        $item->setSlugUk("item-$s"); // повернули стару адресу: правило знято, зворотне записано
        $em->flush();
        self::assertNull($this->target("/services/item-$s/"));
        self::assertSame("/services/item-$s/", $this->target("/services/item-new-$s/"));
        self::assertSame(200, $this->http('GET', 'https://' . $this->apex . "/services/item-$s/")->getStatusCode());

        $post = $em->find(ServicePost::class, $this->postId);
        self::assertNotNull($post);
        $post->setSlugUk("post-new-$s");
        $em->flush();
        self::assertSame("/blog/post-new-$s/", $this->target("/blog/post-$s/"));
        self::assertSame('https://' . $this->apex . "/blog/post-new-$s/", $this->http('GET', 'https://' . $this->apex . "/blog/post-$s/")->headers->get('Location'));

        $district = $em->find(ServiceDistrict::class, $this->districtId);
        self::assertNotNull($district);
        $district->setSlugUk("district-new-$s");
        $em->flush();
        self::assertNull($this->target("/districts/district-$s/"), 'район — не правило сайту');
        self::assertSame("/districts/district-new-$s/", $this->target("/districts/district-$s/", $this->apexCityId), 'а правило свого міста');
        self::assertSame('https://' . $this->apex . "/districts/district-new-$s/", $this->http('GET', 'https://' . $this->apex . "/districts/district-$s/")->headers->get('Location'));
        self::assertSame(404, $this->http('GET', 'https://' . $this->branch . "/districts/district-$s/")->getStatusCode(), 'на хості філії правила нема');

        $category = $em->find(ServiceCategory::class, $this->categoryId);
        self::assertNotNull($category);
        $category->setSlugUk("cat-new-$s");
        $em->flush();
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_redirect WHERE site_id = ? AND source LIKE ?', [$this->siteId, "%cat-$s%"]), 'схема flat: у категорії нема адреси — правил нема');

        // новий запис зі старою адресою знімає правило
        $em->persist((new ServicePost())->setSite($this->site())->setSlugUk("post-$s")->setTitleUk('Знову')->setActive(true)->setPublishedAt(new \DateTimeImmutable('-1 hour')));
        $em->flush();
        self::assertNull($this->target("/blog/post-$s/"));
    }

    public function testManagerMergesChainsAndRejectsCyclesSelfAndLiveSources(): void
    {
        $s = $this->s;
        $m = static::getContainer()->get(RedirectManager::class);
        $site = $this->site();
        [$a, $b, $c, $d] = ["/a-$s/", "/b-$s/", "/c-$s/", "/d-$s/"];
        $m->save($site, null, $a, RedirectCode::Permanent, $b);
        $m->save($site, null, $b, RedirectCode::Permanent, $c); // A→B, B→C ⇒ A→C, B→C
        self::assertSame($c, $this->target($a));
        self::assertSame($c, $this->target($b));
        $m->save($site, null, $d, RedirectCode::Temporary, $a); // D→A, а A→C ⇒ D→C (код свій)
        self::assertSame($c, $this->target($d));
        self::assertSame(RedirectCode::Temporary, $m->find($this->siteId, $this->apexCityId, $d)['code'] ?? null);

        $this->assertInvalid('ту саму адресу', fn () => $m->save($site, null, $c, RedirectCode::Permanent, strtoupper($c) . '?'));
        $this->assertInvalid('Цикл', fn () => $m->save($site, null, $c, RedirectCode::Permanent, $a));
        $this->assertInvalid('Цикл', fn () => $m->save($site, null, $c, RedirectCode::Permanent, $b));
        self::assertNull($m->find($this->siteId, $this->apexCityId, $c), 'відхилене правило не записано');
        self::assertNull($m->check($site, null, $c, RedirectCode::Gone, null), '410 циклів не має');
        $this->assertInvalid('вкажіть ціль', fn () => $m->save($site, null, $c, RedirectCode::Permanent, ''));
        $this->assertInvalid('Невалідна ціль', fn () => $m->save($site, null, $c, RedirectCode::Permanent, 'relative'));

        // живі адреси — відмова; прихована у філії послуга жива (301 у апекс), посилання на район — лише на своєму хості
        $this->assertInvalid('відкривається на сайті', fn () => $m->save($site, null, "/services/item-$s/", RedirectCode::Permanent, "/x-$s/"));
        $this->assertInvalid('відкривається на сайті', fn () => $m->save($site, $this->city($this->branchCityId), "/services/hidden-$s/", RedirectCode::Gone, null));
        $this->assertInvalid('відкривається на сайті', fn () => $m->save($site, null, '/contacts/', RedirectCode::Permanent, "/x-$s/"));
        $this->assertInvalid('відкривається на сайті', fn () => $m->save($site, null, "/districts/district-$s/", RedirectCode::Gone, null), 'район апекса: жива хоча б в одному місті');
        self::assertTrue($m->save($site, $this->city($this->branchCityId), "/districts/district-$s/", RedirectCode::Gone, null), 'а на хості філії — 404, можна');
        $this->assertInvalid('відкривається на сайті', fn () => $m->save($site, null, "/ru/blog/post-$s/", RedirectCode::Gone, null), 'ru-адреса без власного slug жива через uk');
        $this->assertInvalid('відкривається на сайті', fn () => $m->save($site, null, "/blog/post-$s/", RedirectCode::Gone, null));

        // свій хост — шлях; менеджеру чужі домени не можна
        $m->save($site, null, "/own-$s/", RedirectCode::Permanent, 'https://www.' . $this->branch . "/x-$s/");
        self::assertSame("/x-$s/", $this->target("/own-$s/"));
        $m->save($site, null, "/ownq-$s/", RedirectCode::Permanent, 'https://' . $this->apex . "/x-$s/?k=1");
        self::assertSame("/x-$s/?k=1", $this->target("/ownq-$s/"), 'query цілі зберігається; ціль з query — не джерело ланцюга');
        $this->assertInvalid('Цикл', fn () => $m->save($site, null, "/x-$s/", RedirectCode::Permanent, 'https://' . $this->apex . "/own-$s/"));
        $this->assertInvalid('адміністратор', fn () => $m->save($site, null, "/ext-$s/", RedirectCode::Permanent, 'https://other.example/x', allowExternal: false));
        self::assertTrue($m->save($site, null, "/ext-$s/", RedirectCode::Permanent, 'https://other.example/x'));
        self::assertSame('https://other.example/x', $this->target("/ext-$s/"));

        // авто-301: обмін адресами в одному пакеті правил не дає; правила сайту переписуються на нову адресу
        self::assertSame(0, $m->recordMoves($site, null, [["/p-$s/", "/q-$s/"], ["/q-$s/", "/p-$s/"]]));
        $m->save($site, null, "/x1-$s/", RedirectCode::Permanent, "/m-$s/");
        self::assertSame(1, $m->recordMoves($site, null, [["/m-$s/", "/n-$s/"]]));
        self::assertSame("/n-$s/", $this->target("/x1-$s/"), 'X→A ⇒ X→B');
        $m->recordMoves($site, null, [["/n-$s/", "/m-$s/"]]); // назад: A жива — правила з A нема, B→A
        self::assertNull($this->target("/m-$s/"));
        self::assertSame("/m-$s/", $this->target("/n-$s/"));
        self::assertSame("/m-$s/", $this->target("/x1-$s/"));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_redirect WHERE site_id = ? AND source = target', [$this->siteId]), 'жодного правила «на себе»');

        // правило міста: ланцюг зливається в межах міста й сайту; правило сайту не переписує правило міста
        $branch = $this->city($this->branchCityId);
        $m->save($site, $branch, "/cb-$s/", RedirectCode::Permanent, $a); // A→C на сайті ⇒ у філії cb→C
        self::assertSame($c, $this->target("/cb-$s/", $this->branchCityId));
        $m->save($site, $branch, "/ca-$s/", RedirectCode::Permanent, "/zz-$s/");
        $m->save($site, null, "/zz-$s/", RedirectCode::Permanent, "/yy-$s/"); // правило сайту переписує й правило міста на zz
        self::assertSame("/yy-$s/", $this->target("/ca-$s/", $this->branchCityId));
    }

    public function testCsvImportReportsErrorsAndExportRoundTrips(): void
    {
        $s = $this->s;
        $site = $this->site();
        $csv = static::getContainer()->get(RedirectCsv::class);
        static::getContainer()->get(RedirectManager::class)->save($site, null, "/existing-$s/", RedirectCode::Permanent, "/old-target-$s/");
        $file = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, "\xEF\xBB\xBFsource;code;target;city\n"
            . "/a-$s/;301;/b-$s/\n"          // 2: нове
            . "/c-$s/;410;\n"                // 3: 410 без цілі
            . "/existing-$s/;302;/z-$s/\n"   // 4: заміна
            . "/b-$s/;301;/a-$s/\n"          // 5: цикл з рядком 2
            . "/d-$s/;999;/x\n"              // 6: код
            . "/e-$s/;301;\n"                // 7: нема цілі
            . "\n"
            . "relative-$s;301;/x-$s/\n"     // 9: ок — шлях без слеша нормалізується
            . ";301;/y\n"                    // 10: нема джерела
            . "/f-$s/;301;/g-$s/;branch\n"   // 11: правило міста
            . "/h-$s/;301;/g-$s/;nowhere\n"  // 12: невідоме місто
            . "\"/q-$s/\";\"301\";\"https://other.example/q\";;extra\n"); // 13: лапки й зайва колонка
        $report = $csv->import($site, $file);
        unlink($file);

        self::assertSame(5, $report['created'], json_encode($report, \JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(1, $report['updated']);
        self::assertSame([5, 6, 7, 10, 12], array_column($report['errors'], 'line'));
        self::assertStringContainsString('Цикл', $report['errors'][0]['message']);
        self::assertSame("/z-$s/", $this->target("/existing-$s/"));
        self::assertSame("/g-$s/", $this->target("/f-$s/", $this->branchCityId));
        self::assertSame('https://other.example/q', $this->target("/q-$s/"));

        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        $csv->export($site, $out);
        rewind($out);
        $text = (string) stream_get_contents($out);
        self::assertStringStartsWith("\xEF\xBB\xBFsource;code;target;city;kind;hits;last_hit_at\n", $text);
        self::assertStringContainsString("/a-$s/;301;/b-$s/;;manual;0;\n", $text);
        self::assertStringContainsString("/f-$s/;301;/g-$s/;branch;manual;0;\n", $text);
    }

    public function testPackRulesMoveToDatabaseIdempotently(): void
    {
        $s = $this->s;
        $site = $this->site();
        $m = static::getContainer()->get(RedirectManager::class);
        $m->save($site, null, "/manual-$s/", RedirectCode::Permanent, "/keep-$s/");
        $pack = new PackRedirects($m, static::getContainer()->get(LegacyUrls::class), $this->db(), [
            "old-page-$s" => "/new-page-$s/",
            "ru/old-$s" => ['path' => "/ru/new-$s/"],
            "price-$s.html" => "/services/item-$s/",
            "legacy-$s" => ['city' => null, 'item' => "item-$s"],
            "manual-$s" => "/other-$s/",
        ], ["/n-$s"]);
        self::assertSame(['redirects' => 4, 'gone' => 1, 'legacy' => 1], $pack->count());
        $report = $pack->import($site);
        self::assertSame(4, $report['created'], json_encode($report, \JSON_UNESCAPED_UNICODE) ?: '');
        self::assertCount(2, $report['skipped']);
        self::assertSame([], $report['errors']);
        self::assertSame("/new-page-$s/", $this->target("/old-page-$s/"), 'ключ без слешів → джерело зі слешем');
        self::assertSame("/services/item-$s/", $this->target("/price-$s.html"), 'файл — без слеша');
        self::assertSame("/ru/new-$s/", $this->target("/ru/old-$s/"));
        self::assertSame(RedirectCode::Gone, $m->find($this->siteId, $this->apexCityId, "/n-$s/")['code'] ?? null);
        self::assertSame("/keep-$s/", $this->target("/manual-$s/"), 'ручне правило пак не перекриває');
        self::assertSame('pack', $this->db()->fetchOne('SELECT kind FROM service_redirect WHERE site_id = ? AND source = ?', [$this->siteId, "/old-page-$s/"]));

        $again = $pack->import($site);
        self::assertSame(0, $again['created']);
        self::assertSame(4, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_redirect WHERE site_id = ? AND kind = ?', [$this->siteId, 'pack']));
    }

    public function testNotFoundLogAggregatesWithoutPersonalDataAndPrunesPerSite(): void
    {
        $s = $this->s;
        $log = new NotFoundLog($this->db(), 3, 30);
        $cityId = $this->apexCityId;
        foreach (['/wp-login.php', '/.env', '/admin/config.php', '/.git/config', '/xmlrpc.php', '/wp-content/x.js'] as $probe) {
            $log->record($this->siteId, $cityId, $probe, null);
        }
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_not_found WHERE site_id = ?', [$this->siteId]), 'сканування ботів не журналюються');
        $log->record($this->siteId, $cityId, "/gone-$s/", 'https://google.com/search?q=my+phone+0501234567');
        $log->record($this->siteId, $cityId, "/gone-$s/", null);
        $log->record($this->siteId, $cityId, "/gone-$s/", 'not a url');
        $row = $this->db()->fetchAssociative('SELECT hits, referer FROM service_not_found WHERE site_id = ? AND path = ?', [$this->siteId, "/gone-$s/"]);
        self::assertSame(['hits' => 3, 'referer' => 'https://google.com/search'], ['hits' => (int) ($row['hits'] ?? 0), 'referer' => $row['referer'] ?? null], 'query referer відкинуто, порожній не затирає');

        foreach (['a', 'b', 'c', 'd'] as $i => $p) {
            $log->record($this->siteId, $cityId, "/$p-$s/", null);
            $this->db()->executeStatement('UPDATE service_not_found SET last_hit_at = NOW() - INTERVAL ? MINUTE WHERE site_id = ? AND path = ?', [10 - $i, $this->siteId, "/$p-$s/"]);
        }
        $this->db()->executeStatement('UPDATE service_not_found SET last_hit_at = NOW() - INTERVAL 40 DAY WHERE site_id = ? AND path = ?', [$this->siteId, "/gone-$s/"]);
        self::assertSame(2, $log->prune($this->siteId), 'старіше за 30 днів і понад 3 рядки');
        self::assertSame(["/b-$s/", "/c-$s/", "/d-$s/"], $this->db()->fetchFirstColumn('SELECT path FROM service_not_found WHERE site_id = ? ORDER BY path', [$this->siteId]));

        static::getContainer()->get(RedirectManager::class)->save($this->site(), null, "/c-$s/", RedirectCode::Gone, null);
        self::assertSame(["/b-$s/", "/d-$s/"], $this->db()->fetchFirstColumn('SELECT path FROM service_not_found WHERE site_id = ? ORDER BY path', [$this->siteId]), 'правило знімає адресу з журналу');
        self::assertSame(2, $log->clear($this->siteId));
    }

    private function assertInvalid(string $message, callable $save, string $why = ''): void
    {
        try {
            $save();
            self::fail('Очікувалась відмова: ' . $message . ' ' . $why);
        } catch (InvalidRedirect $e) {
            self::assertStringContainsStringIgnoringCase($message, $e->getMessage(), $why);
        }
    }
}
