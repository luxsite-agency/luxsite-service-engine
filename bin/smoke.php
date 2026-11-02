#!/usr/bin/env php
<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.
/**
 * bin/smoke.php <url…> — перевірка релізу без веб-сервера: ядро Symfony (prod) обробляє запити й друкує коди.
 * Використовує bin/site deploy для кандидата до перемикання (FastPanel не має «другого» php-fpm) і після restore.
 * Код виходу 1, якщо хоч один запит не 2xx/3xx. Хост беремо з URL — тенант резолвиться по Host, як у житті.
 * Нічого не друкується до кінця: вивід до старту сесії = «headers already sent» на сторінках із сесією (/login).
 * Автовідкриття: із sitemap.xml беруться перша послуга, перша категорія (nested/root), перший район і перший пост
 * блогу (різні шаблони), а зі сторінки послуги — перша картинка Liip (/media/cache/resolve/…): v0.9.12 зламав фото
 * послуг, а smoke цього не бачив. Адреси залежать від паку: app.catalog.url_scheme (flat/nested/root/slug),
 * app.blog_prefix і app.pages. У slug адреси /<x>/ однакові для послуги, категорії й сторінки: smoke бере першу, середню
 * й останню з карти, а що відкрилося — пише за маршрутом (у звіті «авто: item|category|page»).
 */

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$urls = array_slice($argv, 1);
if ($urls === []) {
    fwrite(STDERR, "bin/smoke.php <url…>\n");
    exit(2);
}

// .env.local релізу (APP_ENV=prod на сервері); без Dotenv у CLI DATABASE_URL не підтягнеться
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
$env = $_SERVER['APP_ENV'] ?? 'prod';
$debug = (bool) ($_SERVER['APP_DEBUG'] ?? false);

// Логи ядра (monolog у stderr) ховаємо — важливі лише коди; власний звіт друкуємо після всіх запитів
ob_start();
$kernel = new Kernel($env, $debug);
$kernel->boot();
// шаблони адрес під схему URL паку (CatalogUrls): якою виглядає послуга, категорія, пост
$scheme = (string) $kernel->getContainer()->getParameter('app.catalog.url_scheme');
$pages = (bool) $kernel->getContainer()->getParameter('app.pages');
$blog = preg_quote((string) $kernel->getContainer()->getParameter('app.blog_prefix'), '~');
$reserved = implode('|', array_map(static fn (string $x) => preg_quote($x, '~'), \App\ServiceSite\CatalogUrls::RESERVED_SEGMENTS));
// перший сегмент, що не є фіксованим розділом: категорія (root, slug), послуга (slug), сторінка (app.pages)
$seg = '(?!(?:'.$reserved.'|'.$blog.')/)[^/]+';
$patterns = [
    'item' => match ($scheme) {
        'nested' => '~^https?://[^/]+/services/[^/]+/[^/]+/$~',
        'root' => '~^https?://[^/]+/'.$seg.'/[^/]+/$~',
        'slug' => null, // /<послуга>/ за адресою не відрізнити від категорії й сторінки — див. $rootPicks
        default => '~^https?://[^/]+/services/[^/]+/$~',
    },
    'category' => match ($scheme) {
        'nested' => '~^https?://[^/]+/services/[^/]+/$~',
        'root' => '~^https?://[^/]+/'.$seg.'/$~',
        default => null,
    },
    'district' => '~^https?://[^/]+/districts/[^/]+/$~',
    'post' => '~^https?://[^/]+/'.$blog.'/(?!page/)[^/]+/$~',
    // дочірня сторінка app.pages /<батько>/<слаг>/ (у root два сегменти — це послуга)
    'page' => $pages && $scheme !== 'root' ? '~^https?://[^/]+/'.$seg.'/[^/]+/$~' : null,
];
// адреси одним сегментом від кореня: у slug — перша, середня й остання з карти (категорії, послуги, сторінки йдуть
// у карті блоками, тож так потрапляють різні шаблони); у flat/nested з app.pages — перша (це сторінка)
$rootRe = '~^https?://[^/]+/'.$seg.'/$~';
$rootPicks = match (true) {
    $scheme === 'slug' => 'first-middle-last',
    $pages && $scheme !== 'root' => 'first',
    default => null,
};
// що насправді відкрилося за адресою з кореня — за маршрутом (у звіті й для перевірки картинки послуги)
$routeKinds = ['sv_service' => 'item', 'sv_category' => 'category', 'sv_page' => 'page'];
$lines = [];
$errors = [];
$fail = 0;
$queue = $urls;
$seen = [];
$auto = [];
while (($url = array_shift($queue)) !== null) {
    if (isset($seen[$url])) {
        continue;
    }
    $seen[$url] = true;
    $request = Request::create($url, 'GET', [], [], [], ['HTTP_USER_AGENT' => 'service-engine smoke']);
    $body = '';
    try {
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $code = $response->getStatusCode();
        $body = (string) $response->getContent();
        if (($auto[$url] ?? null) === 'root') {
            $route = (string) ($request->attributes->get('_canonical_route') ?? $request->attributes->get('_route') ?? '');
            $auto[$url] = $routeKinds[$route] ?? 'root';
        }
    } catch (\Throwable $e) {
        $code = 500;
        $errors[] = sprintf('  %s: %s %s', $url, $e::class, $e->getMessage());
    }
    $ok = $code >= 200 && $code < 400;
    // місто під noindex (демо, місто в розробці): sitemap.xml навмисно 404, а robots.txt каже Disallow: / — це не провал
    if (!$ok && $code === 404 && str_ends_with($url, '/sitemap.xml')) {
        $robots = $kernel->handle(Request::create(preg_replace('~/sitemap\.xml$~', '/robots.txt', $url), 'GET', [], [], [], ['HTTP_USER_AGENT' => 'service-engine smoke']));
        if (str_contains((string) $robots->getContent(), 'Disallow: /')) {
            $ok = true;
            $code = 404;
            $url .= ' (noindex)';
        }
    }
    $fail += $ok ? 0 : 1;
    $lines[] = sprintf('%s %s %d%s', $ok ? 'ok ' : 'BAD', $url, $code, isset($auto[$url]) ? ' (авто: '.$auto[$url].')' : '');
    // автовідкриття: з карти сайту — по одній сторінці кожного шаблону; зі сторінки послуги — перша картинка Liip
    if ($ok && str_ends_with($url, '/sitemap.xml') && preg_match_all('~<loc>([^<]+)</loc>~', $body, $m)) {
        $pick = static function (string $re) use ($m): ?string {
            foreach ($m[1] as $loc) {
                if (preg_match($re, $loc)) {
                    return html_entity_decode($loc);
                }
            }

            return null;
        };
        foreach ($patterns as $kind => $re) {
            $extra = $re !== null ? $pick($re) : null;
            if ($extra !== null && !isset($seen[$extra]) && !isset($auto[$extra])) {
                $auto[$extra] = $kind;
                $queue[] = $extra;
            }
        }
        if ($rootPicks !== null) {
            $roots = array_values(array_filter(array_map('html_entity_decode', $m[1]), static fn (string $loc) => (bool) preg_match($rootRe, $loc)));
            $idx = $roots === [] ? [] : ($rootPicks === 'first' ? [0] : array_unique([0, intdiv(\count($roots), 2), \count($roots) - 1]));
            foreach ($idx as $i) {
                $extra = $roots[$i];
                if (!isset($seen[$extra]) && !isset($auto[$extra])) {
                    $auto[$extra] = 'root';
                    $queue[] = $extra;
                }
            }
        }
    }
    if ($ok && ($auto[$url] ?? null) === 'item' && preg_match('~src="(https?://[^"]+/media/cache/resolve/[^"]+)"~', $body, $im)) {
        $img = html_entity_decode($im[1]);
        if (!isset($seen[$img])) {
            $auto[$img] = 'image';
            $queue[] = $img;
        }
    }
    // сесія наступного запиту має стартувати з чистого стану
    if (\PHP_SESSION_ACTIVE === session_status()) {
        session_write_close();
    }
    $_COOKIE = [];
}
$noise = ob_get_clean();
if ($fail > 0 && $noise !== '') {
    // при провалі — хвіст логів ядра для діагностики
    $tail = array_slice(explode("\n", trim($noise)), -15);
    fwrite(STDERR, implode("\n", $tail)."\n");
}
foreach ($errors as $e) {
    fwrite(STDERR, $e."\n");
}
echo implode("\n", $lines), "\n";
exit($fail === 0 ? 0 : 1);
