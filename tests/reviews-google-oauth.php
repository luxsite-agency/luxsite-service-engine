<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

/**
 * tests/reviews-google-oauth.php off|on — сторінка /reviews/ з креди Google OAuth і без них (ядро Symfony, без веб-сервера).
 *  off (GOOGLE_CLIENT_ID порожній): нема блоку «Залишити відгук», кнопки «Увійти через Google» і технічного тексту,
 *      /connect/google тихо веде назад, після нього на сторінці теж нема повідомлення (раніше — червоний флеш).
 *  on  (креди задані): кнопка є, /connect/google веде на accounts.google.com.
 * Потрібна БД після міграцій (CI: крок «Міграції → схема»); сайт ci-oauth.localhost створюється, якщо його нема.
 */

declare(strict_types=1);

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require dirname(__DIR__).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['off', 'on'], true)) {
    fwrite(STDERR, "tests/reviews-google-oauth.php off|on\n");
    exit(2);
}

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'test', false);
$kernel->boot();

$host = 'ci-oauth.localhost';
/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$site = $em->getRepository(ServiceSite::class)->findOneBy(['slug' => 'ci-oauth']);
if ($site === null) {
    $site = (new ServiceSite())->setSlug('ci-oauth')->setName('CI OAuth')->setApexDomain('ci-oauth.example');
    $city = (new ServiceCity())->setSite($site)->setHost('ci-oauth.example')->setSlug('kyiv')->setIsApex(true)->setNameNomUk('Київ');
    $em->persist($site);
    $em->persist($city);
    $em->flush();
}
$em->clear();

$fails = [];
$cookies = [];
$get = function (string $path) use ($kernel, $host, &$cookies): Response {
    $request = Request::create('http://'.$host.$path, 'GET', [], $cookies);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $c) {
        $cookies[$c->getName()] = (string) $c->getValue();
    }
    $kernel->terminate($request, $response);

    return $response;
};
$check = function (bool $ok, string $what) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ').$what."\n";
    if (!$ok) {
        $fails[] = $what;
    }
};

$page = $get('/reviews/');
$html = (string) $page->getContent();
$check($page->getStatusCode() === 200, "/reviews/ → 200 (є {$page->getStatusCode()})");

if ($mode === 'off') {
    $check(!str_contains($html, '/connect/google'), 'без кредів: нема кнопки «Увійти через Google»');
    $check(!str_contains($html, 'id="add-review"'), 'без кредів: нема блоку «Залишити відгук»');

    $connect = $get('/connect/google?return=/reviews/');
    $check($connect->isRedirect('/reviews/'), '/connect/google без кредів → 302 назад на /reviews/');

    $after = (string) $get('/reviews/')->getContent();
    $check(!str_contains($after, 'GOOGLE_CLIENT') && !str_contains($after, 'не налаштовано'), 'після /connect/google відвідувач не бачить технічного повідомлення');
} else {
    $check(str_contains($html, '/connect/google'), 'з кредами: кнопка «Увійти через Google» є');
    $connect = $get('/connect/google?return=/reviews/');
    $check($connect->isRedirection() && str_starts_with((string) $connect->headers->get('Location'), 'https://accounts.google.com/'), '/connect/google з кредами → accounts.google.com');
}

if ($fails !== []) {
    fwrite(STDERR, count($fails)." перевірок не пройшло\n");
    exit(1);
}
echo "reviews-google-oauth $mode: OK\n";
