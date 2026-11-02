<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

/**
 * tests/pages-templates.php — шаблони сторінок app.pages і noindex записів (02.11.2026) на схемі root (як у сайту, що
 * переїжджає зі старої CMS):
 *  - hub: картки обраних послуг з різних категорій; H1 окремо від заголовка;
 *  - sitemap: HTML-карта (категорії → послуги, сторінки, розділи) без noindex-записів і без «не в карті»;
 *  - form:lead — стандартна форма заявки; form:<невідомий> — сторінка без форми;
 *  - noindex сторінки (і дочірньої), послуги, категорії: meta + X-Robots-Tag, поза sitemap.xml;
 *  - авто-301 при зміні слага сторінки (і її дочірніх);
 *  - app:site:seed з pages: template, items, h1_uk, body_uk, noindex, in_sitemap; items/categories: noindex;
 *  - app.ru_mode: noindex (ru поза індексом, без hreflang, sitemap лише uk) і off (/ru/ 404, без перемикача мов).
 * Ядро Symfony без веб-сервера; потрібна БД після міграцій. Сайти ci-pt-* створюються наново.
 */

declare(strict_types=1);

use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServicePage;
use App\Entity\ServiceSite;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

/** Ядро з параметрами паку поверх test-оточення; свій кеш і свій клас контейнера */
abstract class PtKernel extends Kernel
{
    /** @return array<string, mixed> */
    abstract protected function packParameters(): array;

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/'.$this->environment.'-'.strtolower((new \ReflectionClass($this))->getShortName());
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        $params = $this->packParameters();
        $loader->load(static function (ContainerBuilder $c) use ($params): void {
            foreach ($params as $k => $v) {
                $c->setParameter($k, $v);
            }
        });
    }
}

final class PtRootKernel extends PtKernel
{
    protected function packParameters(): array
    {
        return ['app.catalog.url_scheme' => 'root', 'app.pages' => true];
    }
}

final class PtRuNoindexKernel extends PtKernel
{
    protected function packParameters(): array
    {
        return ['app.catalog.url_scheme' => 'root', 'app.pages' => true, 'app.ru_mode' => 'noindex'];
    }
}

final class PtRuOffKernel extends PtKernel
{
    protected function packParameters(): array
    {
        return ['app.catalog.url_scheme' => 'root', 'app.pages' => true, 'app.ru_mode' => 'off'];
    }
}

$env = $_SERVER['APP_ENV'] ?? 'test';
$kernel = new PtRootKernel($env, false);
$kernel->boot();
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

// ---------- дані ----------
foreach (['ci-pt', 'ci-pt-seed'] as $slug) {
    $old = $em->getRepository(ServiceSite::class)->findOneBy(['slug' => $slug]);
    if ($old !== null) {
        $em->remove($old);
    }
}
$em->flush();
$em->getConnection()->executeStatement("DELETE FROM service_redirect WHERE source LIKE '/ci-pt-%' OR source IN ('/biznes/', '/perekladacham/umovy/')");

$site = (new ServiceSite())->setSlug('ci-pt')->setName('CI Pages')->setApexDomain('ci-pt.example');
$em->persist($site);
$em->persist((new ServiceCity())->setSite($site)->setHost('ci-pt.example')->setSlug('kyiv')->setIsApex(true)->setNameNomUk('Київ'));
$perevod = (new ServiceCategory())->setSite($site)->setSlugUk('perevod')->setTitleUk('Переклад')->setTitleRu('Перевод')->setPosition(10);
$apostil = (new ServiceCategory())->setSite($site)->setSlugUk('apostil')->setTitleUk('Апостиль')->setPosition(20)->setNoindex(true);
$em->persist($perevod);
$em->persist($apostil);
$notar = (new ServiceItem())->setSite($site)->setCategory($perevod)->setSlugUk('notarialnyi')->setTitleUk('Нотаріальний переклад')->setLeadUk('Переклад із засвідченням');
$polsha = (new ServiceItem())->setSite($site)->setCategory($perevod)->setSlugUk('polshcha')->setTitleUk('Переклад для Польщі')->setNoindex(true)->setPosition(20);
$dyplom = (new ServiceItem())->setSite($site)->setCategory($apostil)->setSlugUk('apostil-dyplomu')->setTitleUk('Апостиль на диплом');
$ednist = (new ServiceItem())->setSite($site)->setCategory($perevod)->setSlugUk('usnyi')->setTitleUk('Усний переклад')->setPosition(30);
foreach ([$notar, $polsha, $dyplom, $ednist] as $it) {
    $em->persist($it);
}
$hub = (new ServicePage())->setSite($site)->setSlugUk('biznes')->setTitleUk('Для компаній')->setH1Uk('Переклади для компаній і ФОП')
    ->setTemplate('hub')->addItem($dyplom)->addItem($notar);
$map = (new ServicePage())->setSite($site)->setSlugUk('karta-saitu')->setTitleUk('Карта сайту')->setTemplate('sitemap');
$delivery = (new ServicePage())->setSite($site)->setSlugUk('dostavka-i-oplata')->setTitleUk('Доставка і оплата')->setInSitemap(false);
$hr = (new ServicePage())->setSite($site)->setSlugUk('perekladacham')->setTitleUk('Перекладачам')->setTemplate('form:lead')->setNoindex(true);
$hrChild = (new ServicePage())->setSite($site)->setSlugUk('umovy')->setTitleUk('Умови співпраці')->setParent($hr);
$noForm = (new ServicePage())->setSite($site)->setSlugUk('bez-formy')->setTitleUk('Без форми')->setTemplate('form:nevidoma');
foreach ([$hub, $map, $delivery, $hr, $hrChild, $noForm] as $p) {
    $em->persist($p);
}
$em->flush();
$hubId = (int) $hub->getId();
$hrId = (int) $hr->getId();
$em->clear();

// ---------- перевірки ----------
$fails = [];
$okCount = 0;
$assert = function (bool $cond, string $what) use (&$fails, &$okCount): void {
    if ($cond) {
        ++$okCount;
        echo "ok   $what\n";
    } else {
        $fails[] = $what;
        echo "FAIL $what\n";
    }
};
/** @return array{0: int, 1: string, 2: string, 3: string} код, тіло, Location, X-Robots-Tag */
$get = function (HttpKernelInterface $k, string $url): array {
    $request = Request::create($url);
    try {
        $response = $k->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    } catch (\Throwable $e) {
        return [599, $e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine(), '', ''];
    }
    $k->terminate($request, $response);
    if (\PHP_SESSION_ACTIVE === session_status()) {
        session_write_close();
    }

    return [$response->getStatusCode(), (string) $response->getContent(), (string) $response->headers->get('Location', ''), (string) $response->headers->get('X-Robots-Tag', '')];
};
/** @param list<string> $contains @param list<string> $absent */
$expect = function (HttpKernelInterface $k, string $label, string $url, int $code, array $contains = [], array $absent = [], ?bool $noindex = null, ?string $location = null) use ($get, $assert): void {
    [$got, $body, $loc, $robots] = $get($k, $url);
    $assert($got === $code, sprintf('%s: %s → %d (отримано %d)%s', $label, $url, $code, $got, $got === 599 ? ' — '.$body : ''));
    foreach ($contains as $c) {
        $assert(str_contains($body, $c), sprintf('%s: %s містить «%s»', $label, $url, $c));
    }
    foreach ($absent as $c) {
        $assert(!str_contains($body, $c), sprintf('%s: %s без «%s»', $label, $url, $c));
    }
    if ($noindex !== null) {
        // debug-ядро Symfony ставить свій «noindex» без follow (framework.disallow_search_engine_index) — наш — «noindex, follow»
        $assert(($robots === 'noindex, follow') === $noindex, sprintf('%s: %s X-Robots-Tag %s (отримано «%s»)', $label, $url, $noindex ? 'noindex, follow' : 'не наш', $robots));
    }
    if ($location !== null) {
        $assert($loc === $location, sprintf('%s: %s → Location %s (отримано %s)', $label, $url, $location, $loc));
    }
};

$A = 'http://ci-pt.example';
$META = '<meta name="robots" content="noindex, follow">';
$t = 'root';
$expect($kernel, $t, "$A/biznes/", 200, ['<h1>Переклади для компаній і ФОП</h1>', 'class="block page-hub"', 'href="/apostil/apostil-dyplomu/"', 'href="/perevod/notarialnyi/"'], ['href="/perevod/usnyi/"', $META], false);
[, $hubBody] = $get($kernel, "$A/biznes/");
$assert(strpos($hubBody, '/perevod/notarialnyi/') < strpos($hubBody, '/apostil/apostil-dyplomu/'), 'hub: картки в порядку каталогу (категорія, потім послуга)');
$expect($kernel, $t, "$A/karta-saitu/", 200,
    ['class="block html-sitemap"', '<a href="/perevod/">Переклад</a>', 'href="/perevod/notarialnyi/"', 'href="/apostil/apostil-dyplomu/"', 'href="/biznes/"', 'href="/karta-saitu/"', 'href="/contacts/"'],
    ['href="/perevod/polshcha/"', '<a href="/apostil/">', 'href="/perekladacham/"', 'href="/perekladacham/umovy/"', 'href="/dostavka-i-oplata/"'], false);
$expect($kernel, $t, "$A/perekladacham/", 200, [$META, 'class="page-form"', 'action="/lead"'], [], true);
$expect($kernel, $t, "$A/perekladacham/umovy/", 200, [$META], [], true);
$expect($kernel, $t, "$A/bez-formy/", 200, ['<h1>Без форми</h1>'], ['class="page-form"'], false);
$expect($kernel, $t, "$A/dostavka-i-oplata/", 200, [], [$META], false);
$expect($kernel, $t, "$A/perevod/polshcha/", 200, [$META], [], true);
$expect($kernel, $t, "$A/perevod/notarialnyi/", 200, ['hreflang="ru-UA"'], [$META], false);
$expect($kernel, $t, "$A/apostil/", 200, [$META], [], true);
$expect($kernel, $t, "$A/perevod/", 200, [], [$META], false);
$expect($kernel, $t, "$A/sitemap.xml", 200,
    ["<loc>$A/perevod/notarialnyi/</loc>", "<loc>$A/apostil/apostil-dyplomu/</loc>", "<loc>$A/perevod/</loc>", "<loc>$A/biznes/</loc>", "<loc>$A/karta-saitu/</loc>", "<loc>$A/ru/biznes/</loc>", 'hreflang="ru"'],
    ["$A/perevod/polshcha/", "<loc>$A/apostil/</loc>", "$A/perekladacham/", "$A/dostavka-i-oplata/"]);

// авто-301: слаг хаба і слаг батька (дочірня переїжджає разом з ним)
$em = $kernel->getContainer()->get('doctrine')->getManager();
$em->find(ServicePage::class, $hubId)->setSlugUk('dlya-kompaniy');
$em->find(ServicePage::class, $hrId)->setSlugUk('vakansii-perekladacha');
$em->flush();
$em->clear();
$expect($kernel, 'авто-301', "$A/biznes/", 301, [], [], null, "$A/dlya-kompaniy/");
$expect($kernel, 'авто-301', "$A/ru/biznes/", 301, [], [], null, "$A/ru/dlya-kompaniy/");
$expect($kernel, 'авто-301', "$A/perekladacham/umovy/", 301, [], [], null, "$A/vakansii-perekladacha/umovy/");
$expect($kernel, 'авто-301', "$A/dlya-kompaniy/", 200);

// seed: pages з шаблоном, послугами хаба, H1, body_uk, noindex, in_sitemap; noindex категорії й послуги
$yaml = sys_get_temp_dir().'/ci-pt-seed-'.getmypid().'.yaml';
file_put_contents($yaml, <<<'YAML'
site: {slug: ci-pt-seed, name: CI Pages Seed, apex: ci-pt-seed.example}
cities: [{slug: kyiv, host: ci-pt-seed.example, apex: true, name_nom_uk: Київ}]
categories:
  - {slug: perevod, title_uk: Переклад}
  - {slug: kraini, title_uk: Країни, noindex: true}
items:
  - {slug: notarialnyi, category: perevod, title_uk: Нотаріальний}
  - {slug: niderlandy, category: kraini, title_uk: Нідерланди, noindex: true}
pages:
  - {slug: biznes, template: hub, title_uk: Для компаній, h1_uk: Переклади для бізнесу, items: [niderlandy, notarialnyi], body_uk: '<p>Текст хаба</p>'}
  - {slug: perekladacham, template: 'form:translator', title_uk: Перекладачам, noindex: true}
  - {slug: dostavka, title_uk: Доставка, in_sitemap: false}
YAML);
$app = new Application($kernel);
$app->setAutoExit(false);
$out = new BufferedOutput();
$code = $app->run(new ArrayInput(['command' => 'app:site:seed', 'file' => $yaml]), $out);
$assert($code === 0, 'seed: код 0 ('.trim($out->fetch()).')');
$em->clear();
$seedSite = $em->getRepository(ServiceSite::class)->findOneBy(['slug' => 'ci-pt-seed']);
$pages = [];
foreach ($em->getRepository(ServicePage::class)->findBy(['site' => $seedSite]) as $p) {
    $pages[$p->getSlugUk()] = $p;
}
$assert(isset($pages['biznes']) && $pages['biznes']->getTemplate() === 'hub' && $pages['biznes']->getH1Uk() === 'Переклади для бізнесу' && $pages['biznes']->getDescUk() === '<p>Текст хаба</p>', 'seed: hub з H1 і body_uk');
$assert(isset($pages['biznes']) && $pages['biznes']->getItems()->count() === 2, 'seed: дві послуги хаба');
$assert(isset($pages['perekladacham']) && $pages['perekladacham']->formKey() === 'translator' && $pages['perekladacham']->isNoindex(), 'seed: form:translator, noindex');
$assert(isset($pages['dostavka']) && !$pages['dostavka']->isInSitemap() && !$pages['dostavka']->isNoindex(), 'seed: in_sitemap false');
$assert($em->getRepository(ServiceItem::class)->findOneBy(['site' => $seedSite, 'slugUk' => 'niderlandy'])?->isNoindex() === true, 'seed: noindex послуги');
$assert($em->getRepository(ServiceCategory::class)->findOneBy(['site' => $seedSite, 'slugUk' => 'kraini'])?->isNoindex() === true, 'seed: noindex категорії');
file_put_contents($yaml, "site: {slug: ci-pt-seed, name: CI Pages Seed, apex: ci-pt-seed.example}\npages:\n  - {slug: biznes, title_uk: Для компаній, template: nope}\n");
$code = $app->run(new ArrayInput(['command' => 'app:site:seed', 'file' => $yaml]), $out = new BufferedOutput());
$assert($code !== 0 && str_contains($out->fetch(), 'невідомий шаблон'), 'seed: невідомий шаблон — помилка');
@unlink($yaml);
$kernel->shutdown();

// app.ru_mode: noindex — ru живий, але поза індексом; hreflang нема на обох мовах; sitemap лише uk
$k = new PtRuNoindexKernel($env, false);
$k->boot();
$t = 'ru noindex';
$expect($k, $t, "$A/ru/perevod/notarialnyi/", 200, [$META], ['hreflang='], true);
$expect($k, $t, "$A/perevod/notarialnyi/", 200, ['class="lang-switch'], ['hreflang=', $META], false);
$expect($k, $t, "$A/ru/dlya-kompaniy/", 200, [$META], [], true);
$expect($k, $t, "$A/sitemap.xml", 200, ["<loc>$A/perevod/notarialnyi/</loc>"], ["$A/ru/", 'hreflang']);
$k->shutdown();

// app.ru_mode: off — /ru/ 404, без перемикача мов і hreflang
$k = new PtRuOffKernel($env, false);
$k->boot();
$t = 'ru off';
$expect($k, $t, "$A/ru/perevod/notarialnyi/", 404);
$expect($k, $t, "$A/ru/dlya-kompaniy/", 404);
$expect($k, $t, "$A/ru/", 404);
$expect($k, $t, "$A/perevod/notarialnyi/", 200, [], ['hreflang=', 'class="lang-switch', '/ru/perevod/'], false);
$expect($k, $t, "$A/sitemap.xml", 200, ["<loc>$A/perevod/notarialnyi/</loc>"], ["$A/ru/"]);
$k->shutdown();

echo sprintf("\n== pages-templates: ok %d, FAIL %d\n", $okCount, \count($fails));
exit($fails === [] ? 0 : 1);
