<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * «Інтернет» для завантаження фото за URL (ImageFetcher, app.image_http_client у when@test): відповідь залежить від
 * шляху. Хости тестів — публічні IP (203.0.113.x, TEST-NET-3), щоб перевірка SSRF пройшла без DNS. Кожен запит
 * лічиться в $calls — тест на приватні адреси перевіряє, що до мережі справа не дійшла.
 */
final class ImageFetchMock
{
    /** @var list<string> */
    public static array $calls = [];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        self::$calls[] = $url;
        $path = (string) parse_url($url, \PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/pic.png') => new MockResponse(self::png(64, 48), ['response_headers' => ['content-type' => 'image/png']]),
            str_ends_with($path, '/pic.jpg') => new MockResponse(self::jpeg(80, 60), ['response_headers' => ['content-type' => 'image/jpeg']]),
            str_ends_with($path, '/redir') => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/pic.png']]),
            str_ends_with($path, '/loop') => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/loop']]),
            str_ends_with($path, '/to-private') => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://127.0.0.1/pic.png']]),
            // MockResponse читає тіло цілком ще на getStatusCode і звіряє з Content-Length — тому 20 МБ генератором, а не 'x'
            str_ends_with($path, '/big-declared') => new MockResponse((static function (): \Generator { for ($i = 0; $i < 20; ++$i) { yield str_repeat('x', 1_000_000); } })(), ['response_headers' => ['content-type' => 'image/png', 'content-length' => '20000000']]),
            str_ends_with($path, '/big-stream') => new MockResponse(str_repeat("\x89PNG\r\n\x1a\n", 1_400_000), ['response_headers' => ['content-type' => 'image/png']]),
            str_ends_with($path, '/pic.svg') => new MockResponse('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', ['response_headers' => ['content-type' => 'image/png']]),
            str_ends_with($path, '/missing') => new MockResponse('nope', ['http_code' => 404]),
            default => new MockResponse('<html>not an image</html>', ['response_headers' => ['content-type' => 'image/jpeg']]),
        };
    }

    public static function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, (int) imagecolorallocate($im, 30, 120, 200));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    public static function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, (int) imagecolorallocate($im, 200, 90, 30));
        ob_start();
        imagejpeg($im, null, 85);

        return (string) ob_get_clean();
    }

    public static function gif(int $w, int $h): string
    {
        $im = imagecreate($w, $h);
        imagecolorallocate($im, 0, 0, 0);
        ob_start();
        imagegif($im);

        return (string) ob_get_clean();
    }
}
