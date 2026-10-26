<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

/**
 * tests/team-pages.php on|off — сторінки команди (app.team_prefix) і медична розмітка (app.schema.item_type/addresses).
 *  on  — пак медичного сайту: team_prefix vrachi, IndividualPhysician, додаткове поле «Стаж», MedicalProcedure, усі адреси:
 *        список міста, сторінка лікаря в основній і другій філії (canonical → основна), 301 з міста, де лікар не приймає,
 *        блок «Фахівці» на послузі, sitemap лише з канонічними адресами, Physician/MedicalProcedure у JSON-LD;
 *  off — типові параметри рушія (наявні сайти): маршрутів команди нема, послуга з цінами — Product, адреса — одна.
 * Ядро Symfony без веб-сервера; потрібна БД після міграцій (CI: крок «Міграції → схема»). Сайт ci-team створюється, якщо нема.
 */

declare(strict_types=1);

use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServicePrice;
use App\Entity\ServiceSite;
use App\Entity\ServiceTeamMember;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

require dirname(__DIR__).'/vendor/autoload.php';

$mode = $argv[1] ?? 'on';
if (!in_array($mode, ['on', 'off'], true)) {
    fwrite(STDERR, "usage: php tests/team-pages.php on|off\n");
    exit(2);
}

/** Ядро з параметрами паку медичного сайту (у test-оточенні config/site/parameters.local.yaml не вантажиться) */
final class TeamPagesKernel extends Kernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        $loader->load(static function (ContainerBuilder $c): void {
            $c->setParameter('app.team_prefix', 'vrachi');
            $c->setParameter('app.team_schema_type', 'IndividualPhysician');
            $c->setParameter('app.custom_fields', ['team' => [['key' => 'experience', 'label' => ['uk' => 'Стаж', 'ru' => 'Стаж']]]]);
            $c->setParameter('app.schema', ['legal_name' => '', 'founder' => '', 'opening_hours' => '', 'item_type' => 'MedicalProcedure', 'addresses' => 'all']);
        });
    }

    // свій кеш: маршрути й контейнер інші, ніж у типового test-ядра
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/test-team';
    }
}

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$kernel = $mode === 'on' ? new TeamPagesKernel($_SERVER['APP_ENV'] ?? 'test', false) : new Kernel($_SERVER['APP_ENV'] ?? 'test', false);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

// Дані: два міста (апекс kyiv і bila), дві послуги (B — лише в Києві), три людини: лікар у двох філіях, лікар лише в bila,
// людина без сторінки. Імена вигадані; структура — як у реального медичного сайту (лікар приймає в обох філіях).
if ($em->getRepository(ServiceSite::class)->findOneBy(['slug' => 'ci-team']) === null) {
    $site = (new ServiceSite())->setSlug('ci-team')->setName('CI Clinic')->setApexDomain('ci-team.example')->setSchemaType('MedicalClinic');
    $em->persist($site);
    $kyiv = (new ServiceCity())->setSite($site)->setSlug('kyiv')->setHost('ci-team.example')->setIsApex(true)
        ->setNameNomUk('Київ')->setNameLocUk('Києві')->setAddressUk("вул. Перша, 17\nпр-т Другий, 13-Б");
    $bila = (new ServiceCity())->setSite($site)->setSlug('bila')->setHost('bila.ci-team.example')->setIsApex(false)
        ->setNameNomUk('Біла Церква')->setNameLocUk('Білій Церкві')->setAddressUk('вул. Третя, 5');
    $em->persist($kyiv);
    $em->persist($bila);
    $cat = (new ServiceCategory())->setSite($site)->setSlugUk('uzd')->setTitleUk('УЗД');
    $em->persist($cat);
    $a = (new ServiceItem())->setSite($site)->setCategory($cat)->setSlugUk('uzd-sertsia')->setTitleUk('УЗД серця');
    $b = (new ServiceItem())->setSite($site)->setCategory($cat)->setSlugUk('uzd-shchytovydky')->setTitleUk('УЗД щитовидки')->addHiddenCity($bila);
    $em->persist($a);
    $em->persist($b);
    $em->persist((new ServicePrice())->setItem($a)->setCity($kyiv)->setNameUk('Консультація')->setPrice('600'));
    $em->persist((new ServiceTeamMember())->setCity($kyiv)->addCity($bila)->setNameUk('Іваненко Іван Іванович')->setSlugUk('ivanenko-ivan')
        ->setRoleUk('Кардіолог')->setDescUk('Лікар вищої категорії.')->addItem($a)->addItem($b)->setExtra(['experience' => ['uk' => 'з 2004 року']]));
    $em->persist((new ServiceTeamMember())->setCity($bila)->setNameUk('Петренко Петро Петрович')->setSlugUk('petrenko-petro')
        ->setRoleUk('Невролог')->addItem($a)->setPosition(5));
    $em->persist((new ServiceTeamMember())->setCity($kyiv)->setNameUk('Сидоренко Марія')->setRoleUk('Адміністратор')->setPosition(20));
    $em->flush();
}
$em->clear();

$fails = [];
$get = function (string $host, string $path) use ($kernel, &$fails): ?array {
    $request = Request::create('http://'.$host.$path);
    try {
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    } catch (HttpExceptionInterface $e) {
        // catch=false: 404/301-винятки контролера летять сюди, а не в сторінку помилки
        return [$e->getStatusCode(), '', ''];
    } catch (\Throwable $e) {
        echo "FAIL $host$path: ".$e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."\n";
        $fails[] = "$host$path";

        return null;
    }
    $html = (string) $response->getContent();
    $kernel->terminate($request, $response);

    return [$response->getStatusCode(), $html, (string) $response->headers->get('Location')];
};
$ok = function (bool $cond, string $what) use (&$fails): void {
    echo ($cond ? 'ok   ' : 'FAIL ').$what."\n";
    if (!$cond) {
        $fails[] = $what;
    }
};
/** @return list<array<string, mixed>> */
$ld = static function (string $html): array {
    preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);

    return array_values(array_filter(array_map(static fn ($j) => json_decode($j, true), $m[1])));
};
$types = static fn (array $blocks): array => array_map(static fn ($b) => is_array($b['@type']) ? implode('+', $b['@type']) : $b['@type'], $blocks);
$canonical = static function (string $html): string {
    preg_match('~<link rel="canonical" href="([^"]*)"~', $html, $m);

    return $m[1] ?? '';
};

$K = 'ci-team.localhost';
$B = 'bila.ci-team.localhost';

if ($mode === 'off') {
    [$code] = $get($K, '/vrachi/') ?? [0];
    $ok($code === 404, "off: /vrachi/ без app.team_prefix — 404 (HTTP $code)");
    [$code, $html] = $get($K, '/services/uzd-sertsia/') ?? [0, ''];
    $t = $types($ld($html));
    $ok($code === 200 && in_array('Product', $t, true), 'off: послуга з цінами — Product, як було ('.implode(', ', $t).')');
    $ok(!str_contains($html, '/vrachi/'), 'off: на послузі нема посилань на сторінки команди');
    [$code, $html] = $get($K, '/sitemap.xml') ?? [0, ''];
    $ok($code === 200 && !str_contains($html, '/vrachi/'), 'off: sitemap без сторінок команди');
    [$code, $html] = $get($K, '/contacts/') ?? [0, ''];
    $biz = array_values(array_filter($ld($html), static fn ($b) => ($b['@type'] ?? '') === 'MedicalClinic'))[0] ?? [];
    $ok(isset($biz['address']['streetAddress']) && $biz['address']['streetAddress'] === 'вул. Перша, 17', 'off: у розмітці бізнесу одна адреса (перший рядок)');
} else {
    // список міста: свої люди + лікар з іншого міста, що тут теж приймає
    [$code, $html] = $get($K, '/vrachi/') ?? [0, ''];
    $ok($code === 200, "on: Київ /vrachi/ — 200 (HTTP $code)");
    $ok(str_contains($html, 'Іваненко Іван Іванович') && str_contains($html, 'Сидоренко Марія') && !str_contains($html, 'Петренко'), 'on: Київ /vrachi/ — люди Києва, без лікаря лише з bila');
    $ok(str_contains($html, 'href="/vrachi/ivanenko-ivan/"') && !str_contains($html, 'href="/vrachi/sydorenko'), 'on: посилання лише на людей зі слагом');
    $ok($canonical($html) === "http://$K/vrachi/", 'on: canonical списку — на себе');
    $ok(str_contains($html, 'href="/vrachi/">Команда</a>'), 'on: пункт «Команда» в меню');
    [$code, $html] = $get($B, '/vrachi/') ?? [0, ''];
    $ok($code === 200 && str_contains($html, 'Іваненко') && str_contains($html, 'Петренко'), 'on: bila /vrachi/ — свій лікар і лікар з Києва');

    // сторінка лікаря в основній філії
    [$code, $html] = $get($K, '/vrachi/ivanenko-ivan/') ?? [0, ''];
    $ok($code === 200, "on: Київ /vrachi/ivanenko-ivan/ — 200 (HTTP $code)");
    $ok($canonical($html) === "http://$K/vrachi/ivanenko-ivan/", 'on: canonical основної філії — на себе');
    $ok(str_contains($html, 'Стаж') && str_contains($html, 'з 2004 року'), 'on: додаткове поле «Стаж»');
    $ok(str_contains($html, 'УЗД серця') && str_contains($html, 'УЗД щитовидки'), 'on: блок «Послуги» — обидві послуги Києва');
    $person = array_values(array_filter($ld($html), static fn ($b) => is_array($b['@type'] ?? null)))[0] ?? [];
    $ok(($person['@type'] ?? null) === ['Person', 'IndividualPhysician'], 'on: JSON-LD @type [Person, IndividualPhysician]');
    $ok(count($person['worksFor'] ?? []) === 2 && ($person['worksFor'][0]['@id'] ?? '') === "http://$K/#business"
        && ($person['worksFor'][1]['@id'] ?? '') === "http://$B/#business", 'on: worksFor — дві філії з @id бізнесу міст');
    $ok(($person['practicesAt'] ?? null) === ($person['worksFor'] ?? []), 'on: practicesAt = філії');
    $ok(($person['description'] ?? '') === 'Лікар вищої категорії.' && ($person['jobTitle'] ?? '') === 'Кардіолог', 'on: description — опис людини, jobTitle — роль');
    [$code, $html] = $get($B, '/vrachi/petrenko-petro/') ?? [0, ''];
    $person = array_values(array_filter($ld($html), static fn ($b) => is_array($b['@type'] ?? null)))[0] ?? [];
    $ok($code === 200 && !isset($person['description']) && ($person['worksFor']['@id'] ?? '') === "http://$B/#business", 'on: людина без опису — без description; одна філія — worksFor об\'єктом');

    // та сама сторінка на хості другої філії: canonical → основна, послуги — лише ті, що є в bila
    [$code, $html] = $get($B, '/vrachi/ivanenko-ivan/') ?? [0, ''];
    $ok($code === 200 && $canonical($html) === "http://$K/vrachi/ivanenko-ivan/", 'on: bila /vrachi/ivanenko-ivan/ — 200, canonical на Київ ('.$canonical($html).')');
    $ok(str_contains($html, 'hreflang="uk-UA" href="http://'.$K.'/vrachi/ivanenko-ivan/"'), 'on: hreflang теж на основну філію');
    $ok(str_contains($html, 'УЗД серця') && !str_contains($html, 'УЗД щитовидки'), 'on: bila — без послуги, якої в bila нема');
    [$code, $html] = $get($B, '/ru/vrachi/ivanenko-ivan/') ?? [0, ''];
    $ok($code === 200 && $canonical($html) === "http://$K/ru/vrachi/ivanenko-ivan/", 'on: /ru/ — canonical на ru основної філії');

    // лікар, що не приймає в Києві: 301 на його місто з query; невідомий — 404
    [$code, , $loc] = $get($K, '/vrachi/petrenko-petro/?utm_source=x') ?? [0, '', ''];
    $ok($code === 301 && $loc === "http://$B/vrachi/petrenko-petro/?utm_source=x", "on: Київ /vrachi/petrenko-petro/ — 301 на bila ($code $loc)");
    [$code] = $get($K, '/vrachi/nobody/') ?? [0];
    $ok($code === 404, "on: невідомий слаг — 404 (HTTP $code)");

    // послуга: блок «Фахівці» з людьми міста й медична розмітка без Product і зірок
    [$code, $html] = $get($K, '/services/uzd-sertsia/') ?? [0, ''];
    $ok($code === 200 && str_contains($html, 'Фахівці') && str_contains($html, 'href="/vrachi/ivanenko-ivan/"') && !str_contains($html, 'Петренко'), 'on: Київ послуга — «Фахівці» з лікарем Києва');
    $t = $types($ld($html));
    $ok(in_array('MedicalProcedure+Service', $t, true) && !in_array('Product', $t, true) && !str_contains($html, 'aggregateRating'), 'on: послуга — [MedicalProcedure, Service], без Product і aggregateRating ('.implode(', ', $t).')');
    $ok(str_contains($html, '"lowPrice":"600"'), 'on: ціни лишаються в offers');
    [$code, $html] = $get($B, '/services/uzd-sertsia/') ?? [0, ''];
    $ok($code === 200 && str_contains($html, 'Петренко') && str_contains($html, 'Іваненко'), 'on: bila послуга — обидва лікарі bila');

    // sitemap: на хості міста лише канонічні тут сторінки людей
    [$code, $html] = $get($K, '/sitemap.xml') ?? [0, ''];
    $ok($code === 200 && str_contains($html, "http://$K/vrachi/</loc>") && str_contains($html, "http://$K/vrachi/ivanenko-ivan/</loc>")
        && !str_contains($html, 'petrenko-petro'), 'on: sitemap Києва — список і лікар Києва');
    [$code, $html] = $get($B, '/sitemap.xml') ?? [0, ''];
    $ok($code === 200 && str_contains($html, "http://$B/vrachi/petrenko-petro/</loc>") && !str_contains($html, 'ivanenko-ivan'), 'on: sitemap bila — лише лікар bila');

    // слаг: унікальний у межах сайту (uk і ru разом), формат [a-z0-9-]; той самий слаг на іншому сайті — можна
    $validator = $kernel->getContainer()->get('test.service_container')->get('validator');
    $kyivCity = $em->getRepository(ServiceCity::class)->findOneBy(['host' => 'ci-team.example']);
    $dup = (new ServiceTeamMember())->setCity($kyivCity)->setNameUk('Дубль')->setSlugRu('ivanenko-ivan');
    $paths = array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($validator->validate($dup)));
    $ok($paths === ['slugRu'], 'on: слаг, зайнятий іншою людиною сайту, — помилка на полі ('.implode(', ', $paths).')');
    $bad = (new ServiceTeamMember())->setCity($kyivCity)->setNameUk('Формат')->setSlugUk('Bad Slug');
    $paths = array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($validator->validate($bad)));
    $ok($paths === ['slugUk'], 'on: слаг не [a-z0-9-] — помилка ('.implode(', ', $paths).')');
    $self = $em->getRepository(ServiceTeamMember::class)->findOneBy(['slugUk' => 'ivanenko-ivan']);
    $ok(count($validator->validate($self)) === 0, 'on: збереження людини з її ж слагом — без помилок');

    // розмітка бізнесу: усі адреси міста
    [$code, $html] = $get($K, '/contacts/') ?? [0, ''];
    $biz = array_values(array_filter($ld($html), static fn ($b) => ($b['@type'] ?? '') === 'MedicalClinic'))[0] ?? [];
    $streets = array_column(array_is_list($biz['address'] ?? []) ? $biz['address'] : [], 'streetAddress');
    $ok($streets === ['вул. Перша, 17', 'пр-т Другий, 13-Б'], 'on: у розмітці бізнесу обидві адреси міста ('.json_encode($streets, JSON_UNESCAPED_UNICODE).')');
}

if ($fails !== []) {
    fwrite(STDERR, count($fails)." перевірок не пройшло\n");
    exit(1);
}
echo "team-pages $mode: OK\n";
