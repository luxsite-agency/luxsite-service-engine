<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\MimeTypes;

/**
 * Кеш зображень LiipImagine: адреса файлу в кеші має розширення формату фільтра. Фільтр з `format: webp` віддавав WEBP за
 * адресою `….jpg`, і веб-сервер (тип за розширенням) слав `Content-Type: image/jpeg` з тілом RIFF/WEBP (us217, 26.11.2026).
 * Тут звіряємо те, що бачить веб-сервер: розширення адреси кешу ↔ справжній вміст файлу.
 */
final class ImageCacheFormatTest extends HttpKernelTestCase
{
    private const HOST = 'https://img-ci.example';

    private string $s;
    private string $public;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->s = 'ci-fmt-' . bin2hex(random_bytes(4));
        $this->public = static::getContainer()->getParameter('kernel.project_dir') . '/public';
        (new Filesystem())->mkdir($this->public . '/media/service_page');
        $im = imagecreatetruecolor(1000, 700);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 40, 90, 60));
        imagejpeg($im, $this->source('jpg'));
        imagejpeg($im, $this->source('jpeg'));
        imagepng($im, $this->source('png'));
        imagewebp($im, $this->source('webp'));
    }

    protected function tearDown(): void
    {
        $fs = new Filesystem();
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $fs->remove($this->source($ext));
        }
        foreach (glob($this->public . '/media/cache/*/media/service_page/' . $this->s . '.*') ?: [] as $f) {
            $fs->remove($f);
        }
        foreach (glob($this->public . '/media/cache/*/rc/*/media/service_page/' . $this->s . '.*') ?: [] as $f) {
            $fs->remove($f);
        }
        parent::tearDown();
    }

    /** C1: webp-фільтр → адреса .webp і тіло RIFF/WEBP; jpg-фільтр → image/jpeg */
    public function testCacheUrlExtensionMatchesGeneratedFormat(): void
    {
        $cases = [
            // фільтр, джерело, очікуваний хвіст адреси кешу, тип за вмістом
            ['item_hero', 'jpg', '.jpg.webp', 'image/webp'],
            ['item_hero_sm', 'png', '.png.webp', 'image/webp'],
            ['page_gallery', 'webp', '.webp', 'image/webp'],
            ['hero', 'jpg', '.jpg', 'image/jpeg'],
            ['hero', 'jpeg', '.jpeg', 'image/jpeg'],
            ['hero', 'png', '.png.jpg', 'image/jpeg'],
            ['thumb', 'webp', '.webp.jpg', 'image/jpeg'],
        ];
        foreach ($cases as [$filter, $ext, $tail, $mime]) {
            $label = "$filter ← .$ext";
            $r = $this->http('GET', self::HOST . "/media/cache/resolve/$filter/media/service_page/{$this->s}.$ext");
            self::assertTrue($r->isRedirect(), $label . ': ' . $r->getStatusCode());
            $path = (string) parse_url((string) $r->headers->get('Location'), \PHP_URL_PATH);
            self::assertSame("/media/cache/$filter/media/service_page/{$this->s}$tail", $path, $label);
            $file = $this->public . $path;
            self::assertFileExists($file, $label);
            self::assertSame($mime, (new \finfo(\FILEINFO_MIME_TYPE))->file($file), $label . ': вміст');
            self::assertContains($mime, MimeTypes::getDefault()->getMimeTypes(pathinfo($path, \PATHINFO_EXTENSION)), $label . ': тип за розширенням адреси = тип вмісту');
            if ($mime === 'image/webp') {
                $head = (string) file_get_contents($file, false, null, 0, 12);
                self::assertSame(['RIFF', 'WEBP'], [substr($head, 0, 4), substr($head, 8, 4)], $label);
            }
        }
    }

    /** Після генерації шаблон отримує адресу готового файлу (без resolve) — теж з розширенням формату */
    public function testTwigFilterReturnsStoredUrlWithFormatExtension(): void
    {
        $src = "media/service_page/{$this->s}.jpg";
        $cache = static::getContainer()->get('liip_imagine.cache.manager');
        self::assertStringContainsString("/media/cache/resolve/item_hero/$src", $cache->getBrowserPath($src, 'item_hero'), 'нема в кеші → resolve з адресою оригіналу');
        $this->http('GET', self::HOST . "/media/cache/resolve/item_hero/$src");
        self::assertStringEndsWith("/media/cache/item_hero/$src.webp", $cache->getBrowserPath($src, 'item_hero'));

        // видалення оригіналу чистить і кеш з новим іменем
        $cache->remove($src);
        self::assertFileDoesNotExist($this->public . "/media/cache/item_hero/$src.webp");
    }

    /** C2: стара адреса кешу (розширення оригіналу) без файлу на диску → 301 на генерацію, не 404 */
    public function testLegacyCacheUrlRedirectsInsteadOf404(): void
    {
        $old = "/media/cache/item_hero/media/service_page/{$this->s}.jpg";
        $r = $this->http('GET', self::HOST . $old);
        self::assertSame(301, $r->getStatusCode());
        self::assertSame("/media/cache/resolve/item_hero/media/service_page/{$this->s}.jpg", parse_url((string) $r->headers->get('Location'), \PHP_URL_PATH));
        $r = $this->http('GET', (string) $r->headers->get('Location'));
        self::assertStringEndsWith("/media/cache/item_hero/media/service_page/{$this->s}.jpg.webp", (string) $r->headers->get('Location'));

        // адреси runtime-конфігу паків (rc/<hash>/) — так само
        $r = $this->http('GET', self::HOST . "/media/cache/item_hero/rc/Kii80gyr/media/service_page/{$this->s}.jpg");
        self::assertSame(301, $r->getStatusCode());
        self::assertSame("/media/cache/resolve/item_hero/rc/Kii80gyr/media/service_page/{$this->s}.jpg", parse_url((string) $r->headers->get('Location'), \PHP_URL_PATH));

        // без петлі й зайвих редиректів: адреса вже у форматі фільтра, невідомий фільтр, чужий шлях
        foreach ([
            "/media/cache/item_hero/media/service_page/{$this->s}.jpg.webp",
            "/media/cache/hero/media/service_page/{$this->s}.jpg",
            "/media/cache/nofilter/media/service_page/{$this->s}.jpg",
            '/media/cache/item_hero/../../.env',
        ] as $url) {
            self::assertSame(404, $this->http('GET', self::HOST . $url)->getStatusCode(), $url);
        }
    }

    /** rc/: runtime-конфіг паку (свій розмір на базі webp-фільтра) теж кешується з розширенням формату */
    public function testRuntimeConfigCacheUsesFormatExtension(): void
    {
        $src = "media/service_page/{$this->s}.jpg";
        $cache = static::getContainer()->get('liip_imagine.cache.manager');
        $url = $cache->getBrowserPath($src, 'item_hero', ['thumbnail' => ['size' => [300, 200], 'mode' => 'outbound']]);
        self::assertStringContainsString('/media/cache/resolve/item_hero/rc/', $url);
        $r = $this->http('GET', self::HOST . parse_url($url, \PHP_URL_PATH) . '?' . parse_url($url, \PHP_URL_QUERY));
        self::assertTrue($r->isRedirect(), (string) $r->getStatusCode());
        $path = (string) parse_url((string) $r->headers->get('Location'), \PHP_URL_PATH);
        self::assertMatchesRegularExpression("~^/media/cache/item_hero/rc/[^/]+/media/service_page/{$this->s}\\.jpg\\.webp$~", $path);
        self::assertSame('image/webp', (new \finfo(\FILEINFO_MIME_TYPE))->file($this->public . $path));
        self::assertSame([300, 200], \array_slice((array) getimagesize($this->public . $path), 0, 2));
    }

    private function source(string $ext): string
    {
        return $this->public . "/media/service_page/{$this->s}.$ext";
    }
}
