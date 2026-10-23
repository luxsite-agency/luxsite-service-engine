<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\AdminApi\ImageService;
use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServicePost;
use App\Entity\ServiceReview;
use App\Entity\ServiceSite;
use App\Entity\ServiceTeamMember;
use App\Entity\ServiceWork;
use App\Entity\User;
use App\Tests\Support\ImageFetchMock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Фото через admin API (PUT|POST|DELETE …/{kind}/{id}/image, MCP image_put/image_delete) під токеном content:write:
 * multipart, URL (мережа — ImageFetchMock), base64; блок image у GET; відмови — SVG/GIF, приватні адреси (до мережі
 * не доходить), без дозволу 403, понад 10 МБ 413, ліміт на токен 429. Файли — у public/media/service_*, як з адмінки.
 */
final class ImageApiTest extends HttpKernelTestCase
{
    private string $slug;
    private string $adminHost;
    private int $siteId;
    private int $itemId;
    private int $postId;
    private int $workId;
    private int $globalWorkId;
    private int $teamId;
    private int $reviewId;
    private string $otherSlug;
    private int $otherSiteId;
    private int $otherItemId;
    private string $email;
    private string $bearer;
    private string $readOnly;
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->slug = 'ci-img-' . bin2hex(random_bytes(3));
        $apex = $this->slug . '.example';
        $this->adminHost = 'admin.' . $apex;
        $_SERVER['ADMIN_HOST'] = $_ENV['ADMIN_HOST'] = $this->adminHost;
        putenv('ADMIN_HOST=' . $this->adminHost);
        self::bootKernel();
        $this->mediaDir = static::getContainer()->getParameter('kernel.project_dir') . '/public/media';
        ImageFetchMock::$calls = [];

        $em = $this->em();
        $site = (new ServiceSite())->setSlug($this->slug)->setName('CI Image Site')->setApexDomain($apex);
        $em->persist($site);
        $city = (new ServiceCity())->setSite($site)->setHost($apex)->setSlug('main')->setIsApex(true)->setNameNomUk('Головне');
        $em->persist($city);
        $category = (new ServiceCategory())->setSite($site)->setSlugUk('cat')->setTitleUk('Категорія');
        $em->persist($category);
        $item = (new ServiceItem())->setSite($site)->setCategory($category)->setSlugUk('svc')->setTitleUk('Послуга');
        $em->persist($item);
        $post = (new ServicePost())->setSite($site)->setSlugUk('post')->setTitleUk('Пост');
        $em->persist($post);
        $work = (new ServiceWork())->setCity($city)->setItem($item)->setTitleUk('Робота');
        $em->persist($work);
        $globalWork = (new ServiceWork())->setTitleUk('Робота для всіх сайтів');
        $em->persist($globalWork);
        $team = (new ServiceTeamMember())->setCity($city)->setNameUk('Майстер');
        $em->persist($team);
        $review = (new ServiceReview())->setCity($city)->setAuthorUk('Автор')->setTextUk('Текст')->setStars(5);
        $em->persist($review);
        // чужий сайт тієї ж інсталяції: його записи через {site} першого — 404
        $this->otherSlug = $this->slug . '-other';
        $other = (new ServiceSite())->setSlug($this->otherSlug)->setName('CI Other Site')->setApexDomain($this->otherSlug . '.example');
        $em->persist($other);
        $em->persist((new ServiceCity())->setSite($other)->setHost($this->otherSlug . '.example')->setSlug('main')->setIsApex(true)->setNameNomUk('Інше'));
        $otherCategory = (new ServiceCategory())->setSite($other)->setSlugUk('cat')->setTitleUk('Категорія');
        $em->persist($otherCategory);
        $otherItem = (new ServiceItem())->setSite($other)->setCategory($otherCategory)->setSlugUk('svc')->setTitleUk('Чужа послуга');
        $em->persist($otherItem);
        $this->email = $this->slug . '@example.test';
        $user = (new User())->setEmail($this->email)->setRoles(['ROLE_MODERATOR'])->setPassword('!');
        $em->persist($user);
        $em->flush();
        $this->siteId = (int) $site->getId();
        $this->itemId = (int) $item->getId();
        $this->postId = (int) $post->getId();
        $this->workId = (int) $work->getId();
        $this->globalWorkId = (int) $globalWork->getId();
        $this->teamId = (int) $team->getId();
        $this->reviewId = (int) $review->getId();
        $this->otherSiteId = (int) $other->getId();
        $this->otherItemId = (int) $otherItem->getId();
        $tokens = static::getContainer()->get(AdminApiTokens::class);
        $this->bearer = $tokens->issue($user, 'ci image', [ApiScope::ContentRead, ApiScope::ContentWrite], 1, $user)->plain;
        $this->readOnly = $tokens->issue($user, 'ci image ro', [ApiScope::ContentRead], 1, $user)->plain;
        $em->clear();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        $rows = [[ServiceItem::class, $this->itemId, 'service_item'], [ServicePost::class, $this->postId, 'service_post'], [ServiceWork::class, $this->workId, 'service_work'],
            [ServiceWork::class, $this->globalWorkId, 'service_work'], [ServiceTeamMember::class, $this->teamId, 'service_team'], [ServiceReview::class, $this->reviewId, 'service_review']];
        foreach ($rows as [$class, $id, $dir]) {
            $e = $em->find($class, $id);
            if ($e?->getImageName() !== null) {
                @unlink($this->mediaDir . '/' . $dir . '/' . $e->getImageName());
            }
        }
        $globalWork = $em->find(ServiceWork::class, $this->globalWorkId);
        if ($globalWork !== null) {
            $em->remove($globalWork); // без міста й послуги — каскадом не піде
        }
        foreach ([$this->siteId, $this->otherSiteId] as $siteId) {
            $site = $em->find(ServiceSite::class, $siteId);
            if ($site !== null) {
                $em->remove($site); // категорія, послуга, пост, місто, робота, команда, відгук — ON DELETE CASCADE
            }
        }
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->email]);
        if ($user !== null) {
            $em->remove($user);
        }
        $em->flush();
        parent::tearDown();
    }

    public function testMultipartUploadGetAndDelete(): void
    {
        $response = $this->multipart('items', $this->itemId, ImageFetchMock::png(640, 480), 'kitchen photo.PNG', ['alt' => '  Кухня після прибирання  ']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $image = $this->json($response)['image'];
        self::assertSame(640, $image['width']);
        self::assertSame(480, $image['height']);
        self::assertSame('Кухня після прибирання', $image['alt']);
        self::assertNull($image['alt_ru']);
        self::assertMatchesRegularExpression('~^kitchen-photo-[a-z0-9]+\.png$~', $image['name'], 'ім’я — слаг + унікальний суфікс від Vich, розширення за вмістом');
        self::assertSame('https://' . $this->slug . '.example/media/service_item/' . $image['name'], $image['url']);
        $file = $this->mediaDir . '/service_item/' . $image['name'];
        self::assertFileExists($file);
        self::assertSame($image['bytes'], filesize($file));

        $get = $this->json($this->api('GET', '/items/' . $this->itemId));
        self::assertSame($image['name'], $get['image']['name'], 'GET запису показує блок image');
        self::assertSame('Кухня після прибирання', $get['image_alt']);

        $delete = $this->api('DELETE', '/items/' . $this->itemId . '/image');
        self::assertSame(200, $delete->getStatusCode(), (string) $delete->getContent());
        self::assertNull($this->json($delete)['image']);
        self::assertSame($image['name'], $this->json($delete)['deleted']);
        self::assertFileDoesNotExist($file, 'файл видалено з диска');
        $get = $this->json($this->api('GET', '/items/' . $this->itemId));
        self::assertNull($get['image']);
        self::assertNull($get['image_alt']);

        $again = $this->api('DELETE', '/items/' . $this->itemId . '/image');
        self::assertSame(200, $again->getStatusCode());
        self::assertSame(['image' => null, 'deleted' => null], array_intersect_key($this->json($again), ['image' => 1, 'deleted' => 1]), 'DELETE ідемпотентний: звичайний результат, не помилка');
        self::assertArrayNotHasKey('error', $this->json($again));
    }

    public function testUrlDownloadWithRedirectAndReplacesOldFile(): void
    {
        $first = $this->api('PUT', '/item/' . $this->itemId . '/image', ['url' => 'http://203.0.113.10/photos/redir', 'alt' => 'з URL']);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getContent());
        $image = $this->json($first)['image'];
        self::assertSame([64, 48], [$image['width'], $image['height']]);
        self::assertSame(['http://203.0.113.10/photos/redir', 'http://203.0.113.10/pic.png'], ImageFetchMock::$calls, 'редирект пройдено вручну, кожен хоп перевірено');
        $old = $this->mediaDir . '/service_item/' . $image['name'];
        self::assertFileExists($old);

        $second = $this->api('PUT', '/items/' . $this->itemId . '/image', ['url' => 'https://203.0.113.11/a/b/pic.jpg']);
        self::assertSame(200, $second->getStatusCode(), (string) $second->getContent());
        $new = $this->json($second)['image'];
        self::assertStringEndsWith('.jpg', $new['name']);
        self::assertSame('з URL', $new['alt'], 'alt без нового значення лишається');
        self::assertFileDoesNotExist($old, 'старий оригінал видалено (Vich delete_on_update)');
        self::assertFileExists($this->mediaDir . '/service_item/' . $new['name']);
        self::assertSame($new['name'], $this->json($this->api('GET', '/items/' . $this->itemId))['image']['name']);
    }

    public function testUrlFailures(): void
    {
        $cases = [
            'http://203.0.113.10/loop' => [422, 'редирект'],
            'http://203.0.113.10/missing' => [422, '404'],
            'http://203.0.113.10/page.html' => [422, 'не JPEG/PNG/WebP'],
            'http://203.0.113.10/pic.svg' => [422, 'SVG'],
            'http://203.0.113.10/big-declared' => [413, 'МБ'],
            'http://203.0.113.10/big-stream' => [413, 'МБ'],
            'ftp://203.0.113.10/pic.png' => [422, 'http(s)'],
            'http://user:pw@203.0.113.10/pic.png' => [422, 'облікові'],
        ];
        foreach ($cases as $url => [$status, $text]) {
            $response = $this->api('PUT', '/items/' . $this->itemId . '/image', ['url' => $url]);
            self::assertSame($status, $response->getStatusCode(), $url . ': ' . (string) $response->getContent());
            self::assertStringContainsString($text, (string) $this->json($response)['error'], $url);
        }
        self::assertNull($this->json($this->api('GET', '/items/' . $this->itemId))['image'], 'нічого не записано');
        self::assertSame([], glob($this->mediaDir . '/service_item/*') ?: [], 'без файлів-сиріт');
    }

    public function testPrivateAndLocalUrlsAreRejectedBeforeAnyRequest(): void
    {
        $urls = [
            'http://127.0.0.1/pic.png', 'http://127.1.2.3:8080/pic.png', 'http://10.0.0.5/pic.png', 'http://172.16.4.4/pic.png', 'http://172.31.255.1/pic.png',
            'http://192.168.1.1/pic.png', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/pic.png', 'http://[fc00::1]/pic.png', 'http://[fd12::1]/pic.png',
            'http://[fe80::1]/pic.png', 'http://[::ffff:10.0.0.1]/pic.png', 'http://0.0.0.0/pic.png', 'http://100.64.0.1/pic.png', 'http://localhost/pic.png',
            'http://db.internal/pic.png', 'http://mysql.local/pic.png',
            // нерезолвний хост і hex-IP (dns_get_record/gethostbynamel → false): 422 «не резолвиться», не 500
            'http://no-such-host.invalid/pic.png', 'http://0x7f000001/pic.png',
        ];
        foreach ($urls as $url) {
            $response = $this->api('PUT', '/items/' . $this->itemId . '/image', ['url' => $url]);
            self::assertSame(422, $response->getStatusCode(), $url . ': ' . (string) $response->getContent());
        }
        self::assertStringContainsString('не резолвиться', (string) $this->json($this->api('PUT', '/items/' . $this->itemId . '/image', ['url' => 'http://no-such-host.invalid/pic.png']))['error']);
        self::assertSame([], ImageFetchMock::$calls, 'жодного мережевого запиту');

        // редирект на приватну адресу — другий хоп відхиляється
        $response = $this->api('PUT', '/items/' . $this->itemId . '/image', ['url' => 'http://203.0.113.10/to-private']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('приватні', (string) $this->json($response)['error']);
        self::assertSame(['http://203.0.113.10/to-private'], ImageFetchMock::$calls);
    }

    public function testBase64AndAltRuOnlyForPosts(): void
    {
        $data = base64_encode(ImageFetchMock::jpeg(300, 200));
        $response = $this->api('PUT', '/posts/' . $this->postId . '/image', ['data' => 'data:image/jpeg;base64,' . $data, 'filename' => '../../etc/Обкладинка.exe', 'alt' => 'uk', 'alt_ru' => 'ru']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $image = $this->json($response)['image'];
        self::assertSame(['uk', 'ru', 300, 200], [$image['alt'], $image['alt_ru'], $image['width'], $image['height']]);
        self::assertMatchesRegularExpression('~^[a-z0-9-]+\.jpg$~', $image['name'], 'ім’я з запиту в шлях не потрапляє');
        self::assertFileExists($this->mediaDir . '/service_post/' . $image['name']);
        $get = $this->json($this->api('GET', '/posts/' . $this->postId));
        self::assertSame('ru', $get['image']['alt_ru']);

        $response = $this->api('PUT', '/items/' . $this->itemId . '/image', ['data' => $data, 'alt_ru' => 'ru']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('alt_ru', (string) $this->json($response)['error']);

        self::assertSame(422, $this->api('PUT', '/items/' . $this->itemId . '/image', ['data' => '%%%not-base64%%%'])->getStatusCode());
        self::assertSame(400, $this->api('PUT', '/items/' . $this->itemId . '/image', ['alt' => 'без джерела'])->getStatusCode());
        self::assertSame(400, $this->api('PUT', '/items/' . $this->itemId . '/image', [], 'not json')->getStatusCode());
    }

    public function testSvgGifAndFakeExtensionAreRefusedByContent(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
        $response = $this->multipart('items', $this->itemId, $svg, 'logo.png');
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('SVG', (string) $this->json($response)['error']);

        $response = $this->api('PUT', '/items/' . $this->itemId . '/image', ['data' => base64_encode(ImageFetchMock::gif(10, 10)), 'filename' => 'anim.jpg']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('GIF', (string) $this->json($response)['error']);

        $response = $this->multipart('items', $this->itemId, '<?php echo 1;', 'shell.jpg');
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('не JPEG/PNG/WebP', (string) $this->json($response)['error']);

        self::assertNull($this->json($this->api('GET', '/items/' . $this->itemId))['image']);
        self::assertSame([], glob($this->mediaDir . '/service_item/*') ?: []);
    }

    public function testWriteScopeRequired(): void
    {
        $png = ImageFetchMock::png(10, 10);
        $response = $this->api('PUT', '/items/' . $this->itemId . '/image', ['data' => base64_encode($png)], null, $this->readOnly);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(403, $this->api('DELETE', '/items/' . $this->itemId . '/image', [], null, $this->readOnly)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/items/' . $this->itemId, [], null, $this->readOnly)->getStatusCode(), 'читати можна');
        self::assertSame(401, $this->api('GET', '/items/' . $this->itemId, [], null, 'svc_wrong')->getStatusCode());
        self::assertSame(404, $this->api('PUT', '/districts/1/image', ['data' => base64_encode($png)])->getStatusCode(), 'kind без фото — маршруту нема');
        self::assertSame(404, $this->api('PUT', '/items/999999999/image', ['data' => base64_encode($png)])->getStatusCode());
    }

    public function testOversizedIsRejectedBeforeDecodingOrWriting(): void
    {
        $limit = ImageService::MAX_BYTES;
        $response = $this->api('PUT', '/items/' . $this->itemId . '/image', ['data' => str_repeat('A', (int) ceil($limit * 4 / 3) + 64)]);
        self::assertSame(413, $response->getStatusCode(), (string) $response->getContent());

        $response = $this->multipart('items', $this->itemId, ImageFetchMock::png(8, 8) . str_repeat("\0", $limit), 'huge.png');
        self::assertSame(413, $response->getStatusCode(), (string) $response->getContent());

        self::assertNull($this->json($this->api('GET', '/items/' . $this->itemId))['image']);
        self::assertSame([], glob($this->mediaDir . '/service_item/*') ?: []);
    }

    public function testTooManyUploadsPerTokenGive429(): void
    {
        $limit = (int) static::getContainer()->getParameter('app.admin_api_image_rate_limit');
        $data = base64_encode(ImageFetchMock::png(4, 4));
        for ($i = 0; $i < $limit; ++$i) {
            self::assertSame(200, $this->api('PUT', '/items/' . $this->itemId . '/image', ['data' => $data])->getStatusCode(), 'завантаження №' . ($i + 1));
        }
        $response = $this->api('PUT', '/items/' . $this->itemId . '/image', ['data' => $data]);
        self::assertSame(429, $response->getStatusCode(), (string) $response->getContent());
        self::assertNotNull($response->headers->get('Retry-After'));
        self::assertSame(200, $this->api('DELETE', '/items/' . $this->itemId . '/image')->getStatusCode(), 'видалення не лімітується');
        self::assertSame([], glob($this->mediaDir . '/service_item/*') ?: [], 'після замін і видалення файлів нема');
    }

    public function testMcpImagePutAndDelete(): void
    {
        $call = fn (string $tool, array $args): array => $this->json($this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]));
        $tools = array_column($this->json($this->mcp(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']))['result']['tools'], 'name');
        self::assertContains('image_put', $tools);
        self::assertContains('image_delete', $tools);

        $result = $call('image_put', ['site' => $this->slug, 'kind' => 'items', 'id' => $this->itemId, 'data' => base64_encode(ImageFetchMock::png(32, 16)), 'filename' => 'mcp.png', 'alt' => 'через MCP']);
        self::assertArrayNotHasKey('isError', $result['result'], json_encode($result));
        $image = $result['result']['structuredContent']['image'];
        self::assertSame([32, 16, 'через MCP'], [$image['width'], $image['height'], $image['alt']]);

        $bad = $call('image_put', ['site' => $this->slug, 'kind' => 'items', 'id' => $this->itemId, 'url' => 'http://192.168.0.1/pic.png']);
        self::assertTrue($bad['result']['isError']);
        self::assertStringContainsString('приватні', $bad['result']['content'][0]['text']);

        $deleted = $call('image_delete', ['site' => $this->slug, 'kind' => 'item', 'id' => $this->itemId]);
        self::assertArrayNotHasKey('isError', $deleted['result']);
        self::assertSame($image['name'], $deleted['result']['structuredContent']['deleted']);
        self::assertFileDoesNotExist($this->mediaDir . '/service_item/' . $image['name']);
    }

    public function testWorksTeamReviewsListGetPatchAndImages(): void
    {
        foreach (['works' => 2, 'team' => 1, 'reviews' => 1] as $kind => $count) {
            $list = $this->json($this->api('GET', '/' . $kind));
            self::assertSame($count, $list['count'], $kind);
            self::assertFalse($list['items'][0]['has_image']);
        }
        self::assertSame(1, $this->json($this->api('GET', '/works?city=main&item=' . $this->itemId))['count']);

        // валідатор сутності, як у формі адмінки: stars лише 1–5
        foreach ([0, 6] as $stars) {
            $response = $this->api('PATCH', '/reviews/' . $this->reviewId, ['stars' => $stars]);
            self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
            self::assertStringContainsString('stars', (string) $this->json($response)['error']);
        }
        self::assertSame(5, $this->json($this->api('GET', '/reviews/' . $this->reviewId))['stars'], 'не записано');
        $patched = $this->json($this->api('PATCH', '/reviews/' . $this->reviewId, ['stars' => 4, 'image_alt' => 'Фото автора']));
        self::assertSame([4, ['stars', 'image_alt']], [$patched['stars'], $patched['changed']]);
        self::assertSame('Нова назва', $this->json($this->api('PATCH', '/works/' . $this->workId, ['title_uk' => 'Нова назва']))['title_uk']);

        // фото трьома шляхами — у теки своїх мапінгів Vich
        $work = $this->json($this->multipart('works', $this->workId, ImageFetchMock::jpeg(50, 40), 'before-after.jpg'))['image'];
        $team = $this->json($this->api('PUT', '/team/' . $this->teamId . '/image', ['data' => base64_encode(ImageFetchMock::png(30, 30)), 'alt' => 'Майстер']))['image'];
        $review = $this->json($this->api('PUT', '/review/' . $this->reviewId . '/image', ['url' => 'http://203.0.113.10/pic.png']))['image'];
        self::assertFileExists($this->mediaDir . '/service_work/' . $work['name']);
        self::assertFileExists($this->mediaDir . '/service_team/' . $team['name']);
        self::assertFileExists($this->mediaDir . '/service_review/' . $review['name']);
        self::assertStringContainsString('/media/service_team/', $team['url']);
        self::assertSame('Майстер', $this->json($this->api('GET', '/team/' . $this->teamId))['image']['alt']);
        self::assertTrue($this->json($this->api('GET', '/reviews'))['items'][0]['has_image']);
        self::assertSame(422, $this->api('PUT', '/team/' . $this->teamId . '/image', ['data' => base64_encode(ImageFetchMock::png(5, 5)), 'alt_ru' => 'x'])->getStatusCode(), 'alt_ru лише posts');

        foreach (['works' => $this->workId, 'team' => $this->teamId, 'reviews' => $this->reviewId] as $kind => $id) {
            self::assertSame(200, $this->api('DELETE', '/' . $kind . '/' . $id . '/image')->getStatusCode(), $kind);
            self::assertNull($this->json($this->api('GET', '/' . $kind . '/' . $id))['image'], $kind);
        }
        foreach (['service_work', 'service_team', 'service_review'] as $dir) {
            self::assertSame([], glob($this->mediaDir . '/' . $dir . '/*') ?: [], $dir);
        }
    }

    public function testRecordsOfAnotherSiteAre404AndGlobalWorkIsSharedByDesign(): void
    {
        $png = base64_encode(ImageFetchMock::png(6, 6));
        self::assertSame(404, $this->api('GET', '/items/' . $this->otherItemId)->getStatusCode());
        self::assertSame(404, $this->api('PATCH', '/items/' . $this->otherItemId, ['title_uk' => 'x'])->getStatusCode());
        self::assertSame(404, $this->api('PUT', '/items/' . $this->otherItemId . '/image', ['data' => $png])->getStatusCode());
        self::assertSame(404, $this->api('DELETE', '/items/' . $this->otherItemId . '/image')->getStatusCode());
        self::assertSame([], glob($this->mediaDir . '/service_item/*') ?: []);
        // робота, прив'язана до міста першого сайту, через другий сайт — 404; глобальна (без міста й послуги) — видна обом
        self::assertSame(404, $this->api('GET', '/works/' . $this->workId, [], null, null, $this->otherSlug)->getStatusCode());
        self::assertSame(404, $this->api('PUT', '/works/' . $this->workId . '/image', ['data' => $png], null, null, $this->otherSlug)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/works/' . $this->globalWorkId, [], null, null, $this->otherSlug)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/works/' . $this->globalWorkId)->getStatusCode());
        self::assertSame([$this->globalWorkId], array_column($this->json($this->api('GET', '/works', [], null, null, $this->otherSlug))['items'], 'id'));
        $image = $this->json($this->api('PUT', '/works/' . $this->globalWorkId . '/image', ['data' => $png], null, null, $this->otherSlug))['image'];
        self::assertStringStartsWith('https://' . $this->otherSlug . '.example/media/service_work/', $image['url']);
        self::assertSame($image['name'], $this->json($this->api('GET', '/works/' . $this->globalWorkId))['image']['name']);
        self::assertSame(200, $this->api('DELETE', '/works/' . $this->globalWorkId . '/image')->getStatusCode());
        self::assertSame([], glob($this->mediaDir . '/service_work/*') ?: []);
    }

    public function testLiipCacheOfOldFileIsRemovedOnReplaceAndDelete(): void
    {
        $data = base64_encode(ImageFetchMock::png(12, 12));
        $first = $this->json($this->api('PUT', '/items/' . $this->itemId . '/image', ['data' => $data]))['image']['name'];
        $cached = [];
        foreach (['item_hero', 'item_hero_sm', 'teaser'] as $filter) {
            $dir = $this->mediaDir . '/cache/' . $filter . '/media/service_item';
            @mkdir($dir, 0775, true);
            file_put_contents($cached[] = $dir . '/' . $first, 'variant');
        }
        $second = $this->json($this->api('PUT', '/items/' . $this->itemId . '/image', ['data' => $data]))['image']['name'];
        self::assertNotSame($first, $second);
        foreach ($cached as $file) {
            self::assertFileDoesNotExist($file, 'кеш старого файлу прибрано при заміні');
        }
        $dir = $this->mediaDir . '/cache/item_hero/media/service_item';
        file_put_contents($dir . '/' . $second, 'variant');
        self::assertSame(200, $this->api('DELETE', '/items/' . $this->itemId . '/image')->getStatusCode());
        self::assertFileDoesNotExist($dir . '/' . $second, 'кеш прибрано при DELETE');
    }

    public function testMultipartBeyondPostMaxSizeGives413NotMissingFile(): void
    {
        // PHP понад post_max_size мовчки викидає $_FILES — контролер бачить multipart без файлів і Content-Length
        $request = Request::create('https://' . $this->adminHost . '/api/admin/v1/' . $this->slug . '/items/' . $this->itemId . '/image', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->bearer, 'CONTENT_TYPE' => 'multipart/form-data; boundary=ci', 'CONTENT_LENGTH' => '99999999',
        ]);
        $response = $this->send($request);
        self::assertSame(413, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('post_max_size', (string) $this->json($response)['error']);
        self::assertSame(400, $this->multipartWithoutFile()->getStatusCode(), 'малий multipart без file — як і було, 400');
    }

    private function multipartWithoutFile(): Response
    {
        return $this->send(Request::create('https://' . $this->adminHost . '/api/admin/v1/' . $this->slug . '/items/' . $this->itemId . '/image', 'POST', ['alt' => 'x'], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->bearer, 'CONTENT_TYPE' => 'multipart/form-data; boundary=ci', 'CONTENT_LENGTH' => '120',
        ]));
    }

    /** @param array<string, mixed> $json */
    private function api(string $method, string $path, array $json = [], ?string $raw = null, ?string $bearer = null, ?string $site = null): Response
    {
        $body = $raw ?? ($json === [] ? '' : (string) json_encode($json));

        return $this->send(Request::create('https://' . $this->adminHost . '/api/admin/v1/' . ($site ?? $this->slug) . $path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . ($bearer ?? $this->bearer),
            'CONTENT_TYPE' => 'application/json',
        ], $body));
    }

    /** @param array<string, string> $fields */
    private function multipart(string $kind, int $id, string $content, string $clientName, array $fields = []): Response
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ci-img-');
        file_put_contents($tmp, $content);
        $file = new UploadedFile($tmp, $clientName, 'image/png', null, true);

        return $this->send(Request::create('https://' . $this->adminHost . '/api/admin/v1/' . $this->slug . '/' . $kind . '/' . $id . '/image', 'POST', $fields, [], ['file' => $file], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->bearer,
            'CONTENT_TYPE' => 'multipart/form-data; boundary=ci',
        ]));
    }

    /** @param array<string, mixed> $message */
    private function mcp(array $message): Response
    {
        return $this->send(Request::create('https://' . $this->adminHost . '/mcp', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->bearer,
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode($message)));
    }

    private function send(Request $request): Response
    {
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data, (string) $response->getContent());

        return $data;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
