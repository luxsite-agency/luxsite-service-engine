<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

/**
 * tests/reviews-title-city.php — місто в title/H1/og:title сторінки відгуків (app.reviews.title_city: auto, типово).
 *  - сайт із двома містами: на кожному хості своє місто в місцевому відмінку, прийменник узгоджено з брендом
 *    («Майстри» в Чернівцях, Cleanmax у Києві / в Одесі), ru — «в Черновцах»;
 *  - сайт одного міста на апексі: без міста («…про компанію Single Co»).
 * Ядро Symfony без веб-сервера; потрібна БД після міграцій (CI: крок «Міграції → схема»). Сайти ci-rtc-* створюються, якщо нема.
 */

declare(strict_types=1);

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'test', false);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

/** @param list<array{0: string, 1: bool, 2: string, 3: string, 4: string}> $cities slug, apex, nom, loc uk, loc ru */
$ensure = function (string $slug, string $name, ?string $brandUk, ?string $brandRu, array $cities) use ($em): void {
    if ($em->getRepository(ServiceSite::class)->findOneBy(['slug' => $slug]) !== null) {
        return;
    }
    $site = (new ServiceSite())->setSlug($slug)->setName($name)->setApexDomain($slug.'.example')
        ->setBrandNameUk($brandUk)->setBrandNameRu($brandRu);
    $em->persist($site);
    foreach ($cities as [$citySlug, $apex, $nom, $locUk, $locRu]) {
        $em->persist((new ServiceCity())->setSite($site)->setHost($apex ? $slug.'.example' : $citySlug.'.'.$slug.'.example')
            ->setSlug($citySlug)->setIsApex($apex)->setNameNomUk($nom)->setNameLocUk($locUk)->setNameLocRu($locRu));
    }
    $em->flush();
};
$ensure('ci-rtc-multi', 'CI Multi', '«Майстри»', '«Мастера»', [
    ['kyiv', true, 'Київ', 'Києві', 'Киеве'],
    ['chernivtsi', false, 'Чернівці', 'Чернівцях', 'Черновцах'],
]);
$ensure('ci-rtc-latin', 'CI Latin', 'Cleanmax', null, [
    ['kyiv', true, 'Київ', 'Києві', 'Киеве'],
    ['odesa', false, 'Одеса', 'Одесі', 'Одессе'],
]);
$ensure('ci-rtc-single', 'Single Co', null, null, [
    ['kyiv', true, 'Київ', 'Києві', 'Киеве'],
]);
$em->clear();

$fails = [];
$check = function (string $host, string $path, string $want) use ($kernel, &$fails): void {
    $request = Request::create('http://'.$host.$path);
    try {
        $response = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, false);
    } catch (\Throwable $e) {
        echo "FAIL $host$path: ".$e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."
";
        $fails[] = "$host$path";

        return;
    }
    $html = (string) $response->getContent();
    $kernel->terminate($request, $response);
    preg_match('~<title>(.*?)</title>~s', $html, $t);
    preg_match('~<h1[^>]*>(.*?)</h1>~s', $html, $h);
    preg_match('~<meta property="og:title" content="([^"]*)"~', $html, $og);
    $got = [
        'title' => html_entity_decode(trim($t[1] ?? '')),
        'h1' => html_entity_decode(trim(strip_tags($h[1] ?? ''))),
        'og:title' => html_entity_decode(trim($og[1] ?? '')),
    ];
    foreach ($got as $what => $value) {
        $ok = $response->getStatusCode() === 200 && $value === $want;
        echo ($ok ? 'ok   ' : 'FAIL ')."$host$path $what = «{$value}»".($ok ? '' : " (очікували «{$want}», HTTP {$response->getStatusCode()})")."\n";
        if (!$ok) {
            $fails[] = "$host$path $what";
        }
    }
};

$check('ci-rtc-multi.localhost', '/reviews/', 'Відгуки клієнтів про компанію «Майстри» в Києві');
$check('chernivtsi.ci-rtc-multi.localhost', '/reviews/', 'Відгуки клієнтів про компанію «Майстри» в Чернівцях');
$check('chernivtsi.ci-rtc-multi.localhost', '/ru/reviews/', 'Отзывы клиентов о компании «Мастера» в Черновцах');
$check('ci-rtc-latin.localhost', '/reviews/', 'Відгуки клієнтів про компанію Cleanmax у Києві');
$check('odesa.ci-rtc-latin.localhost', '/reviews/', 'Відгуки клієнтів про компанію Cleanmax в Одесі');
$check('ci-rtc-single.localhost', '/reviews/', 'Відгуки клієнтів про компанію Single Co');

if ($fails !== []) {
    fwrite(STDERR, count($fails)." перевірок не пройшло\n");
    exit(1);
}
echo "reviews-title-city: OK\n";
