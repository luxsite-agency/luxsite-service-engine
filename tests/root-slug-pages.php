<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

/**
 * tests/root-slug-pages.php — адреси від кореня: схема каталогу slug (/<послуга>/, /<категорія>/) і сторінки app.pages
 * (/<слаг>/, /<батько>/<слаг>/), на двох хостах одного сайту (апекс + місто на окремому домені).
 *  - slug + app.pages: послуга, категорія, сторінка, дочірня сторінка uk/ru; невідомий слаг — 404 (не категорія),
 *    app.gone_prefixes — 410, legacy-301, слеш; послуга, прихована в місті, — 301 на апекс; сторінка, прихована в місті, —
 *    404; sitemap кожного хоста; футер; збіги слагів (RootSlugs::conflicts/conflictsFor, app:site:slugs);
 *  - root + app.pages: сторінка перекриває /<категорія>/, дочірня — /<категорія>/<послуга>/; каталог працює, як був;
 *  - типове ядро (flat, app.pages: false): ті самі дані в БД нічого не змінюють — /vakansii/ 404, футер і карта без сторінок.
 * Ядро Symfony без веб-сервера; потрібна БД після міграцій (CI: крок «Міграції → схема»). Сайти ci-rsp-* створюються наново.
 */

declare(strict_types=1);

use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServicePage;
use App\Entity\ServiceSite;
use App\Entity\User;
use App\Kernel;
use App\ServiceSite\RootSlugs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

/** Ядро з параметрами паку поверх test-оточення (пак у тестах не вантажиться); свій кеш і свій клас контейнера */
abstract class RspKernel extends Kernel
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

final class RspSlugKernel extends RspKernel
{
    protected function packParameters(): array
    {
        return [
            'app.catalog.url_scheme' => 'slug',
            'app.pages' => true,
            'app.gone_prefixes' => ['/staroe'],
            'app.legacy_redirects' => ['stara-adresa' => '/autizm-kiev/'],
        ];
    }
}

final class RspRootKernel extends RspKernel
{
    protected function packParameters(): array
    {
        return ['app.catalog.url_scheme' => 'root', 'app.pages' => true];
    }
}

$env = $_SERVER['APP_ENV'] ?? 'test';
$slugKernel = new RspSlugKernel($env, false);
$slugKernel->boot();
/** @var EntityManagerInterface $em */
$em = $slugKernel->getContainer()->get('doctrine')->getManager();

// ---------- дані ----------
foreach (['ci-rsp', 'ci-rsp-bad'] as $slug) {
    $old = $em->getRepository(ServiceSite::class)->findOneBy(['slug' => $slug]);
    if ($old !== null) {
        $em->remove($old);
    }
}
$em->flush();

$site = (new ServiceSite())->setSlug('ci-rsp')->setName('CI Root Slugs')->setApexDomain('ci-rsp.example');
$em->persist($site);
$kyiv = (new ServiceCity())->setSite($site)->setHost('ci-rsp.example')->setSlug('kyiv')->setIsApex(true)
    ->setNameNomUk('Київ')->setNameLocUk('Києві')->setNameLocRu('Киеве');
$bc = (new ServiceCity())->setSite($site)->setHost('ci-rsp-bc.example')->setSlug('bila-tserkva')->setIsApex(false)
    ->setNameNomUk('Біла Церква')->setNameLocUk('Білій Церкві')->setNameLocRu('Белой Церкви');
$em->persist($kyiv);
$em->persist($bc);
$cat = (new ServiceCategory())->setSite($site)->setSlugUk('nevrologiya')->setTitleUk('Неврологія')->setTitleRu('Неврология');
$em->persist($cat);
$long = 'diagnostyka-ta-likuvannia-porushen-movlennia-u-ditei-doshkilnoho-viku'; // понад 64 символи: було VARCHAR(64)
$autism = (new ServiceItem())->setSite($site)->setCategory($cat)->setSlugUk('autizm-kiev')->setSlugRu('autizm-kiev')
    ->setTitleUk('Аутизм у дітей')->setTitleRu('Аутизм у детей')->addHiddenCity($bc);
$longItem = (new ServiceItem())->setSite($site)->setCategory($cat)->setSlugUk($long)->setTitleUk('Затримка розвитку');
$shared = (new ServiceItem())->setSite($site)->setCategory($cat)->setSlugUk('eeg')->setTitleUk('ЕЕГ');
foreach ([$autism, $longItem, $shared] as $it) {
    $em->persist($it);
}
$vak = (new ServicePage())->setSite($site)->setSlugUk('vakansii')->setTitleUk('Вакансії')->setTitleRu('Вакансии')
    ->setLeadUk('Працюйте з нами')->setInFooter(true);
$child = (new ServicePage())->setSite($site)->setSlugUk('likar-nevrolog')->setTitleUk('Лікар-невролог')->setLeadUk('Повний день')->setParent($vak);
$lic = (new ServicePage())->setSite($site)->setSlugUk('licenzii-i-sertifikaty')->setTitleUk('Ліцензії')->addHiddenCity($bc);
$off = (new ServicePage())->setSite($site)->setSlugUk('arhiv')->setTitleUk('Архів')->setActive(false);
foreach ([$vak, $child, $lic, $off] as $p) {
    $em->persist($p);
}
// сайт зі збігами слагів: послуга й категорія на /konflikt/, сторінка на адресі фіксованого розділу /about/
$bad = (new ServiceSite())->setSlug('ci-rsp-bad')->setName('CI Bad')->setApexDomain('ci-rsp-bad.example');
$em->persist($bad);
$em->persist((new ServiceCity())->setSite($bad)->setHost('ci-rsp-bad.example')->setSlug('kyiv')->setIsApex(true)->setNameNomUk('Київ'));
$badCat = (new ServiceCategory())->setSite($bad)->setSlugUk('konflikt')->setTitleUk('Категорія-конфлікт');
$em->persist($badCat);
$em->persist((new ServiceItem())->setSite($bad)->setCategory($badCat)->setSlugUk('konflikt')->setTitleUk('Послуга-конфлікт'));
$em->persist((new ServicePage())->setSite($bad)->setSlugUk('about')->setTitleUk('Про нас (сторінка)'));
$em->flush();
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
/** @return array{0: int, 1: string, 2: string} код, тіло, Location */
$get = function (HttpKernelInterface $kernel, string $url): array {
    $request = Request::create($url);
    try {
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    } catch (\Throwable $e) {
        return [599, $e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine(), ''];
    }
    $kernel->terminate($request, $response);
    if (\PHP_SESSION_ACTIVE === session_status()) {
        session_write_close();
    }

    return [$response->getStatusCode(), (string) $response->getContent(), (string) $response->headers->get('Location', '')];
};
$expect = function (HttpKernelInterface $kernel, string $label, string $url, int $code, ?string $contains = null, ?string $location = null, ?string $absent = null) use ($get, $assert): void {
    [$got, $body, $loc] = $get($kernel, $url);
    $detail = $got === 599 ? ' — '.$body : '';
    $assert($got === $code, sprintf('%s: %s → %d (отримано %d)%s', $label, $url, $code, $got, $detail));
    if ($contains !== null) {
        $assert(str_contains($body, $contains), sprintf('%s: %s містить «%s»', $label, $url, $contains));
    }
    if ($absent !== null) {
        $assert(!str_contains($body, $absent), sprintf('%s: %s без «%s»', $label, $url, $absent));
    }
    if ($location !== null) {
        $assert($loc === $location, sprintf('%s: %s → Location %s (отримано %s)', $label, $url, $location, $loc));
    }
};

$A = 'http://ci-rsp.example';
$B = 'http://ci-rsp-bc.example';
$k = $slugKernel;
$t = 'slug';
$expect($k, $t, "$A/autizm-kiev/", 200, '<link rel="canonical" href="'.$A.'/autizm-kiev/"');
$expect($k, $t, "$A/ru/autizm-kiev/", 200, 'hreflang="uk-UA" href="'.$A.'/autizm-kiev/"');
$expect($k, $t, "$A/$long/", 200);
$expect($k, $t, "$A/nevrologiya/", 200, 'href="/autizm-kiev/"');
$expect($k, $t, "$A/ru/nevrologiya/", 200);
$expect($k, $t, "$A/vakansii/", 200, 'href="/vakansii/likar-nevrolog/"');
$expect($k, $t, "$A/ru/vakansii/", 200, 'Вакансии');
$expect($k, $t, "$A/vakansii/likar-nevrolog/", 200, 'href="/vakansii/"');
$expect($k, $t, "$A/ru/vakansii/likar-nevrolog/", 200);
$expect($k, $t, "$A/licenzii-i-sertifikaty/", 200);
$expect($k, $t, "$A/likar-nevrolog/", 404);
$expect($k, $t, "$A/vakansii/autizm-kiev/", 404);
$expect($k, $t, "$A/arhiv/", 404);
$expect($k, $t, "$A/nevidomyi-slug/", 404);
$expect($k, $t, "$A/ru/nevidomyi-slug/", 404);
$expect($k, $t, "$A/staroe/x/", 410);
$expect($k, $t, "$A/stara-adresa/", 301, null, "$A/autizm-kiev/");
$expect($k, $t, "$A/vakansii", 301, null, "$A/vakansii/");
$expect($k, $t, "$A/services/", 200);
$expect($k, $t, "$A/faq/", 200, 'href="/vakansii/"');
$expect($k, $t, "$A/sitemap.xml", 200, "<loc>$A/vakansii/likar-nevrolog/</loc>", null, '/arhiv/');
$expect($k, $t, "$A/sitemap.xml", 200, "<loc>$A/$long/</loc>");
$expect($k, $t, "$A/sitemap.xml", 200, "<loc>$A/nevrologiya/</loc>");
// місто на окремому домені
$expect($k, $t, "$B/autizm-kiev/", 301, null, "$A/autizm-kiev/");
$expect($k, $t, "$B/eeg/", 200, '<link rel="canonical" href="'.$B.'/eeg/"');
$expect($k, $t, "$B/vakansii/", 200);
$expect($k, $t, "$B/licenzii-i-sertifikaty/", 404);
$expect($k, $t, "$B/sitemap.xml", 200, "<loc>$B/vakansii/</loc>", null, 'licenzii-i-sertifikaty');
$expect($k, $t, "$B/sitemap.xml", 200, "<loc>$B/eeg/</loc>", null, 'autizm-kiev');

// збіги слагів
/** @var RootSlugs $rootSlugs */
$rootSlugs = $slugKernel->getContainer()->get('test.service_container')->get(RootSlugs::class);
$em = $slugKernel->getContainer()->get('doctrine')->getManager();
$site = $em->getRepository(ServiceSite::class)->findOneBy(['slug' => 'ci-rsp']);
$badSite = $em->getRepository(ServiceSite::class)->findOneBy(['slug' => 'ci-rsp-bad']);
$assert($rootSlugs->conflicts($site) === [], 'conflicts(ci-rsp): збігів нема — '.implode(' | ', $rootSlugs->conflicts($site)));
$badLines = $rootSlugs->conflicts($badSite);
$assert((bool) array_filter($badLines, static fn ($l) => str_starts_with($l, 'uk /konflikt/:') && str_contains($l, 'категорія') && str_contains($l, 'послуга')), 'conflicts(ci-rsp-bad): /konflikt/ — послуга й категорія');
$assert((bool) array_filter($badLines, static fn ($l) => str_starts_with($l, 'uk /about/:') && str_contains($l, 'фіксованого розділу')), 'conflicts(ci-rsp-bad): сторінка на /about/');
$cat = $em->getRepository(ServiceCategory::class)->findOneBy(['site' => $site, 'slugUk' => 'nevrologiya']);
$newCat = (new ServiceCategory())->setSite($site)->setSlugUk('autizm-kiev')->setTitleUk('x');
$assert($rootSlugs->conflictsFor($newCat, 'uk') !== [], 'conflictsFor: нова категорія зі слагом послуги');
$assert($rootSlugs->conflictsFor($newCat, 'ru') !== [], 'conflictsFor: те саме в ru (послуга має slugRu = autizm-kiev)');
$newPage = (new ServicePage())->setSite($site)->setSlugUk('nevrologiya')->setTitleUk('x');
$assert($rootSlugs->conflictsFor($newPage, 'uk') !== [], 'conflictsFor: нова сторінка зі слагом категорії');
$assert($rootSlugs->conflictsFor((new ServicePage())->setSite($site)->setSlugUk('contacts')->setTitleUk('x'), 'uk') !== [], 'conflictsFor: сторінка на фіксованому розділі');
$assert($rootSlugs->conflictsFor((new ServicePage())->setSite($site)->setSlugUk('eeg')->setTitleUk('x')->setParent($em->getRepository(ServicePage::class)->findOneBy(['site' => $site, 'slugUk' => 'vakansii'])), 'uk') === [], 'conflictsFor: дочірня сторінка зі слагом послуги — інша адреса, можна');
$assert($rootSlugs->conflictsFor((new ServiceItem())->setSite($site)->setCategory($cat)->setSlugUk('vakansii')->setTitleUk('x'), 'uk') !== [], 'conflictsFor: нова послуга зі слагом сторінки');
$assert($rootSlugs->conflictsFor($em->getRepository(ServiceItem::class)->findOneBy(['site' => $site, 'slugUk' => 'eeg']), 'uk') === [], 'conflictsFor: наявна послуга сама з собою не конфліктує');
$app = new Application($slugKernel);
$app->setAutoExit(false);
$out = new BufferedOutput();
$assert($app->run(new ArrayInput(['command' => 'app:site:slugs', 'site' => 'ci-rsp']), $out) === 0, 'app:site:slugs ci-rsp → 0');
$out = new BufferedOutput();
$assert($app->run(new ArrayInput(['command' => 'app:site:slugs', 'site' => 'ci-rsp-bad']), $out) === 1 && str_contains($out->fetch(), '/konflikt/'), 'app:site:slugs ci-rsp-bad → 1 і рядок /konflikt/');

// ---------- адмінка: форма сторінки (галерея з файлом) і перевірка слага ----------
$container = $slugKernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
$admin = $em->getRepository(User::class)->findOneBy(['email' => 'ci-rsp-admin@example.com'])
    ?? (new User())->setEmail('ci-rsp-admin@example.com')->setRoles(['ROLE_ADMIN'])->setPassword('!');
$em->persist($admin);
$em->flush();
// як KernelBrowser::loginUser: токен у сесії фаєрвола main
$session = $container->get('session.factory')->createSession();
$session->set('_security_main', serialize(new UsernamePasswordToken($admin, 'main', $admin->getRoles())));
$session->save();
$adminHost = 'http://'.($_SERVER['ADMIN_HOST'] ?? 'service-sites.localhost');
$adminReq = function (string $method, string $path, array $params = [], array $files = []) use ($slugKernel, $session, $adminHost): array {
    $request = Request::create($adminHost.$path, $method, $params, [$session->getName() => $session->getId()], $files);
    $response = $slugKernel->handle($request);
    $slugKernel->terminate($request, $response);

    return [$response->getStatusCode(), (string) $response->getContent(), (string) $response->headers->get('Location', '')];
};
/** Поля форми, як їх надіслав би браузер (name → value), уже вкладеним масивом */
$formData = static function (string $html, string $form): array {
    $dom = new \DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8">'.$html);
    $pairs = [];
    foreach ((new \DOMXPath($dom))->query('//*[@name]') as $el) {
        /** @var \DOMElement $el */
        $name = $el->getAttribute('name');
        if (!str_starts_with($name, $form.'[')) {
            continue;
        }
        $tag = strtolower($el->tagName);
        $type = strtolower($el->getAttribute('type'));
        if ($tag === 'input' && \in_array($type, ['checkbox', 'radio'], true)) {
            if ($el->hasAttribute('checked')) {
                $pairs[] = [$name, $el->getAttribute('value') ?: '1'];
            }
        } elseif ($tag === 'input' && $type !== 'file') {
            $pairs[] = [$name, $el->getAttribute('value')];
        } elseif ($tag === 'textarea') {
            $pairs[] = [$name, $el->textContent];
        } elseif ($tag === 'select') {
            foreach ($el->getElementsByTagName('option') as $o) {
                if ($o->hasAttribute('selected')) {
                    $pairs[] = [$name, $o->getAttribute('value')];
                }
            }
        }
    }
    parse_str(implode('&', array_map(static fn ($p) => rawurlencode($p[0]).'='.rawurlencode($p[1]), $pairs)), $data);

    return $data;
};
[$code, $html] = $adminReq('GET', '/admin/service-page/new');
$assert($code === 200 && str_contains($html, 'ServicePage[slugUk]') && str_contains($html, 'ServicePage[images]'), 'адмінка: форма нової сторінки з галереєю → 200');
$data = $formData($html, 'ServicePage');
$site = $em->getRepository(ServiceSite::class)->findOneBy(['slug' => 'ci-rsp']);
$data['ServicePage']['site'] = (string) $site->getId();
$data['ServicePage']['titleUk'] = 'Ліцензії (адмінка)';
$data['ServicePage']['slugUk'] = 'nevrologiya'; // слаг категорії
$data['ea'] = ['newForm' => ['btn' => 'saveAndReturn']];
[$code, $html] = $adminReq('POST', '/admin/service-page/new', $data);
$assert($code === 422 && str_contains($html, 'уже зайнята') && str_contains($html, 'категорія «Неврологія»'), 'адмінка: слаг категорії — 422 і помилка біля поля (отримано '.$code.')');
$data['ServicePage']['slugUk'] = 'contacts';
[$code, $html] = $adminReq('POST', '/admin/service-page/new', $data);
$assert($code === 422 && str_contains($html, 'фіксованим розділом'), 'адмінка: слаг фіксованого розділу — 422');
$png = tempnam(sys_get_temp_dir(), 'rsp').'.png';
$img = imagecreatetruecolor(60, 85);
imagepng($img, $png);
$data['ServicePage']['slugUk'] = 'licenzii-admin';
$data['ServicePage']['images'] = [0 => ['captionUk' => 'Ліцензія МОЗ', 'captionRu' => '', 'position' => '1']];
$files = ['ServicePage' => ['images' => [0 => ['imageFile' => ['file' => new UploadedFile($png, 'scan.png', 'image/png', null, true)]]]]];
[$code, $html, $loc] = $adminReq('POST', '/admin/service-page/new', $data, $files);
$assert($code === 302, 'адмінка: валідна сторінка з файлом галереї → 302 (отримано '.$code.')'.($code !== 302 ? ' '.substr(strip_tags($html), 0, 300) : ''));
$em->clear();
$created = $em->getRepository(ServicePage::class)->findOneBy(['site' => $site->getId(), 'slugUk' => 'licenzii-admin']);
$imageName = $created?->getImages()->first() ? $created->getImages()->first()->getImageName() : null;
$assert($imageName !== null && is_file($slugKernel->getProjectDir().'/public/media/service_page/'.$imageName), 'адмінка: зображення галереї збережено');
$expect($slugKernel, 'slug', "$A/licenzii-admin/", 200, 'Ліцензія МОЗ');
$expect($slugKernel, 'slug', "$A/licenzii-admin/", 200, '/page_gallery/media/service_page/'.$imageName);
// форма редагування сторінки з дочірніми (батько обмежений сторінками верхнього рівня)
$vakId = $em->getRepository(ServicePage::class)->findOneBy(['site' => $site->getId(), 'slugUk' => 'vakansii'])->getId();
[$code, $html] = $adminReq('GET', '/admin/service-page/'.$vakId.'/edit');
$assert($code === 200, 'адмінка: редагування сторінки → 200');
[$code, $html] = $adminReq('GET', '/admin/service-page');
$assert($code === 200 && str_contains($html, '/vakansii/likar-nevrolog/'), 'адмінка: список сторінок з колонкою «Адреса» → 200');
[$code, $html] = $adminReq('GET', '/admin');
$assert($code === 200 && str_contains($html, 'Сторінки'), 'адмінка: пункт меню «Сторінки» з app.pages');
if ($imageName !== null) {
    @unlink($slugKernel->getProjectDir().'/public/media/service_page/'.$imageName);
}
@unlink($png);
$slugKernel->shutdown();

// root + сторінки: сторінка перекриває /<категорія>/ (пріоритет 5 > 0), дочірня — /<категорія>/<послуга>/
$rootKernel = new RspRootKernel($env, false);
$rootKernel->boot();
$t = 'root';
$expect($rootKernel, $t, "$A/vakansii/", 200, 'href="/vakansii/likar-nevrolog/"');
$expect($rootKernel, $t, "$A/vakansii/likar-nevrolog/", 200);
$expect($rootKernel, $t, "$A/nevrologiya/", 200, 'href="/nevrologiya/eeg/"');
$expect($rootKernel, $t, "$A/nevrologiya/eeg/", 200);
$expect($rootKernel, $t, "$A/nevidomyi-slug/", 404);
$rootKernel->shutdown();

// типове ядро: flat, app.pages: false — сторінки в БД ні на що не впливають
$plain = new Kernel($env, false);
$plain->boot();
$t = 'flat';
$expect($plain, $t, "$A/vakansii/", 404);
$expect($plain, $t, "$A/vakansii/likar-nevrolog/", 404);
$expect($plain, $t, "$A/autizm-kiev/", 404);
$expect($plain, $t, "$A/services/eeg/", 200, null, null, 'href="/vakansii/"');
$expect($plain, $t, "$A/sitemap.xml", 200, "<loc>$A/services/eeg/</loc>", null, '/vakansii/');
$plain->shutdown();

echo sprintf("\n== root-slug-pages: ok %d, FAIL %d\n", $okCount, \count($fails));
exit($fails === [] ? 0 : 1);
