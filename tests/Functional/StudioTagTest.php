<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServicePostCategory;
use App\Entity\ServicePostTag;
use App\Entity\ServiceSite;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Теги статей з панелі (24.10.2026, новинні бренди — люди з прямою мовою): tags = [{name, slug}] шукається за слагом серед
 * тегів бренду, нового нема — створюється з назвою; набір поста замінюється повністю ([] — без тегів), без поля — не
 * чіпається; рубрика окремо. Сторінка /<блог>/tag/<slug>/ з пагінацією індексується з першої статті (25.10.2026); без статей — noindex, follow і поза sitemap.
 */
final class StudioTagTest extends HttpKernelTestCase
{
    /** app.brands у when@test: рубрики від кореня й 2 статті на сторінку */
    private const SLUG = 'ci-studiotag';

    private string $admin;
    private string $host;
    private string $email;
    private int $siteId = 0;
    private string $serviceSlug = '';
    private int $serviceId = 0;
    /** @var array<string, string> */
    private array $auth = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->admin = (string) ($_SERVER['ADMIN_HOST'] ?? getenv('ADMIN_HOST') ?: 'admin.ci.local');
        $em = $this->em();
        $em->getConnection()->executeStatement('DELETE FROM service_site WHERE slug = ?', [self::SLUG]); // після обірваного прогону
        $suffix = bin2hex(random_bytes(3));
        $this->host = self::SLUG . '-' . $suffix . '.example';
        $site = (new ServiceSite())->setSlug(self::SLUG)->setName('CI tags')->setApexDomain($this->host)->setMode(ServiceSite::MODE_CONTENT)->setBlogPrefix('novyny');
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->host)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        $em->persist((new ServicePostCategory())->setSite($site)->setSlugUk('politics')->setTitleUk('Політика'));
        // сервісний сайт поруч: tags він ігнорує, як і category_slug
        $this->serviceSlug = 'ci-studiotag-svc-' . $suffix;
        $svc = (new ServiceSite())->setSlug($this->serviceSlug)->setName('CI svc')->setApexDomain($this->serviceSlug . '.example');
        $em->persist($svc);
        $em->persist((new ServiceCity())->setSite($svc)->setHost($this->serviceSlug . '.example')->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        $this->email = 'ci-studiotag-' . $suffix . '@example.com';
        $user = (new User())->setEmail($this->email)->setRoles(['ROLE_MODERATOR'])->setPassword('x')->setSites([self::SLUG, $this->serviceSlug]);
        $em->persist($user);
        $em->flush();
        $this->siteId = (int) $site->getId();
        $this->serviceId = (int) $svc->getId();

        /** @var AdminApiTokens $tokens */
        $tokens = self::getContainer()->get(AdminApiTokens::class);
        $issued = $tokens->issue($user, 'студія', [ApiScope::StudioWrite], 7, $user, [self::SLUG, $this->serviceSlug]);
        $this->auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $issued->plain, 'CONTENT_TYPE' => 'application/json'];
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE t FROM admin_api_token t JOIN `user` u ON u.id = t.user_id WHERE u.email = ?', [$this->email]);
        $conn->executeStatement('DELETE FROM `user` WHERE email = ?', [$this->email]);
        $conn->executeStatement('DELETE FROM service_site WHERE id IN (?, ?)', [$this->siteId, $this->serviceId]);
        parent::tearDown();
    }

    public function testTagsFromStudio(): void
    {
        $oleh = ['name' => 'Олег Григоров', 'slug' => 'oleh-hryhorov'];
        $tramp = ['name' => 'Дональд Трамп', 'slug' => 'donald-tramp'];

        $a1 = $this->put('a1', '00000000-0000-4000-8000-0000000000d1', 'hryhorov-zaiava', ['tags' => [$oleh], 'category_slug' => 'politics']);
        self::assertSame(201, $a1->getStatusCode(), (string) $a1->getContent());
        self::assertSame(['oleh-hryhorov'], $this->json($a1)['tags'] ?? null);
        self::assertSame('politics', $this->json($a1)['category'] ?? null);

        // наявний тег — за слагом, навіть без назви; новий — з назвою
        $a2 = $this->put('a2', '00000000-0000-4000-8000-0000000000d2', 'tramp-i-hryhorov', ['tags' => [['slug' => 'oleh-hryhorov'], $tramp]]);
        self::assertSame(201, $a2->getStatusCode(), (string) $a2->getContent());
        self::assertEqualsCanonicalizing(['oleh-hryhorov', 'donald-tramp'], $this->json($a2)['tags'] ?? null);
        // повтор тієї самої статті — без дублів тегів
        self::assertSame(200, $this->put('a1', '00000000-0000-4000-8000-0000000000d1', 'hryhorov-zaiava', ['tags' => [$oleh]])->getStatusCode());
        // переклад тим самим слагом тегу — той самий тег
        $ru = $this->put('a1ru', '00000000-0000-4000-8000-0000000000d1', 'hryhorov-zaiavlenie', ['lang' => 'ru', 'tags' => [['name' => 'Олег Григоров', 'slug' => 'oleh-hryhorov']]]);
        self::assertSame(200, $ru->getStatusCode(), (string) $ru->getContent());
        self::assertSame(['oleh-hryhorov', 'donald-tramp'], $this->tagSlugs(), 'теги бренду без дублів');
        self::assertSame('Олег Григоров', $this->tag('oleh-hryhorov')->getTitleUk());
        self::assertTrue($this->tag('donald-tramp')->isActive());

        // без поля tags — теги поста не змінюються; рубрика й теги незалежні
        self::assertSame(200, $this->put('a1', '00000000-0000-4000-8000-0000000000d1', 'hryhorov-zaiava', [])->getStatusCode());
        self::assertSame(['oleh-hryhorov'], $this->postTags('hryhorov-zaiava'));
        self::assertSame('politics', $this->post('hryhorov-zaiava')->getCategory()?->slug('uk'), 'теги не чіпають рубрику');
        // tags: null — як відсутнє поле
        self::assertSame(200, $this->put('a1', '00000000-0000-4000-8000-0000000000d1', 'hryhorov-zaiava', ['tags' => null])->getStatusCode());
        self::assertSame(['oleh-hryhorov'], $this->postTags('hryhorov-zaiava'));

        // набір замінюється повністю
        $replaced = $this->put('a1', '00000000-0000-4000-8000-0000000000d1', 'hryhorov-zaiava', ['tags' => [$tramp]]);
        self::assertSame(['donald-tramp'], $this->json($replaced)['tags'] ?? null);
        self::assertSame(['donald-tramp'], $this->postTags('hryhorov-zaiava'));
        self::assertSame('politics', $this->post('hryhorov-zaiava')->getCategory()?->slug('uk'));
        // [] — без тегів; сам тег у бренді лишається
        $cleared = $this->put('a1', '00000000-0000-4000-8000-0000000000d1', 'hryhorov-zaiava', ['tags' => []]);
        self::assertSame(200, $cleared->getStatusCode());
        self::assertSame([], $this->json($cleared)['tags'] ?? null);
        self::assertSame([], $this->postTags('hryhorov-zaiava'));
        self::assertSame(['oleh-hryhorov', 'donald-tramp'], $this->tagSlugs());
        self::assertSame(['oleh-hryhorov', 'donald-tramp'], $this->postTags('tramp-i-hryhorov'), 'інші статті не зачеплено');
    }

    /**
     * Набір тегів — за мовою, якою його задано (studio_tags_lang, рев'ю #28): студія пише оригінал мовою свого сайту, не
     * переносить tags у переклад (шле []), а слаг перекладу — транслітерація імені. Переклад лише дописує назву своєю мовою
     * тегам, що вже є в статті.
     */
    public function testTranslationKeepsTags(): void
    {
        $group = '00000000-0000-4000-8000-0000000000a1';
        self::assertSame(201, $this->put('z-uk', $group, 'zelenskyi-zaiava', ['tags' => [['name' => 'Володимир Зеленський', 'slug' => 'volodymyr-zelenskyi']]])->getStatusCode());

        $ru = $this->put('z-ru', $group, 'zelenskii-zaiavlenie', ['lang' => 'ru', 'tags' => []]);
        self::assertSame(200, $ru->getStatusCode(), (string) $ru->getContent());
        self::assertSame(['volodymyr-zelenskyi'], $this->json($ru)['tags'] ?? null, 'ru-переклад з [] не стирає теги статті');
        // інший слаг (транслітерація ru) — не новий тег і не заміна
        self::assertSame(200, $this->put('z-ru', $group, 'zelenskii-zaiavlenie', ['lang' => 'ru', 'tags' => [['name' => 'Владимир Зеленский', 'slug' => 'vladimir-zelenskii']]])->getStatusCode());
        self::assertSame(['volodymyr-zelenskyi'], $this->tagSlugs());
        self::assertSame(['volodymyr-zelenskyi'], $this->postTags('zelenskyi-zaiava'));
        // той самий слаг — дописується ru-назва, uk не змінюється
        self::assertSame(200, $this->put('z-ru', $group, 'zelenskii-zaiavlenie', ['lang' => 'ru', 'tags' => [['name' => 'Владимир Зеленский', 'slug' => 'volodymyr-zelenskyi']]])->getStatusCode());
        $this->em()->clear();
        $tag = $this->tag('volodymyr-zelenskyi');
        self::assertSame('Володимир Зеленський', $tag->getTitleUk());
        self::assertSame('Владимир Зеленский', $tag->getTitleRu());
        self::assertNull($tag->getSlugRu(), 'ru бере uk-слаг');
        $ruPage = $this->get('https://' . $this->host . '/ru/novyny/tag/volodymyr-zelenskyi/');
        self::assertSame(200, $ruPage->getStatusCode());
        self::assertStringContainsString('<h1>Владимир Зеленский</h1>', (string) $ruPage->getContent());
        // мова набору знову замінює його
        self::assertSame(200, $this->put('z-uk', $group, 'zelenskyi-zaiava', ['tags' => []])->getStatusCode());
        self::assertSame([], $this->postTags('zelenskyi-zaiava'));

        // оригінал ru (студійний сайт з мовою ru на бренді uk+ru): тег створюється з ru-назвою і в uk-полях, набором керує ru;
        // uk-переклад ставить uk-назву тегу, але набір не змінює
        $g2 = '00000000-0000-4000-8000-0000000000a2';
        self::assertSame(201, $this->put('p-ru', $g2, 'peskov-zaiavlenie', ['lang' => 'ru', 'tags' => [['name' => 'Дмитрий Песков', 'slug' => 'dmytro-pieskov']]])->getStatusCode());
        self::assertSame(200, $this->put('p-ru', $g2, 'peskov-zaiavlenie', ['lang' => 'ru', 'tags' => []])->getStatusCode());
        $this->em()->clear();
        self::assertSame([], $this->post('peskov-zaiavlenie')->getTags()->toArray());
        self::assertSame(200, $this->put('p-ru', $g2, 'peskov-zaiavlenie', ['lang' => 'ru', 'tags' => [['name' => 'Дмитрий Песков', 'slug' => 'dmytro-pieskov']]])->getStatusCode());
        self::assertSame(200, $this->put('p-uk', $g2, 'pieskov-zaiava', ['tags' => [['name' => 'Дмитро Пєсков', 'slug' => 'dmytro-pieskov']]])->getStatusCode());
        $this->em()->clear();
        $tag = $this->tag('dmytro-pieskov');
        self::assertSame('Дмитро Пєсков', $tag->getTitleUk(), 'uk-копію ru-назви замінено');
        self::assertSame('Дмитрий Песков', $tag->getTitleRu());
        self::assertSame(['volodymyr-zelenskyi', 'dmytro-pieskov'], $this->tagSlugs());
        self::assertSame(200, $this->put('p-uk', $g2, 'pieskov-zaiava', ['tags' => []])->getStatusCode());
        self::assertSame(['dmytro-pieskov'], $this->postTags('pieskov-zaiava'), 'uk-переклад ru-оригіналу з [] не стирає теги');
        self::assertSame(200, $this->put('p-ru', $g2, 'peskov-zaiavlenie', ['lang' => 'ru', 'tags' => []])->getStatusCode());
        self::assertSame([], $this->postTags('pieskov-zaiava'), 'ru-оригінал керує набором і після перекладу');
    }

    public function testAdminValidationAndRedirects(): void
    {
        self::assertSame(201, $this->put('v1', '00000000-0000-4000-8000-0000000000b1', 'validatsiia', ['tags' => [['name' => 'Олег Григоров', 'slug' => 'oleh-hryhorov']]])->getStatusCode());
        $this->em()->clear();
        /** @var \Symfony\Component\Validator\Validator\ValidatorInterface $validator */
        $validator = self::getContainer()->get('validator');
        $site = $this->em()->find(ServiceSite::class, $this->siteId) ?? self::fail('бренд є');
        $svc = $this->em()->find(ServiceSite::class, $this->serviceId) ?? self::fail('сайт є');

        $dup = (new ServicePostTag())->setSite($site)->setSlugUk('oleh-hryhorov')->setTitleUk('Дубль');
        self::assertContains('slugUk', array_map(static fn ($v): string => $v->getPropertyPath(), iterator_to_array($validator->validate($dup))), 'дубль слагу — помилка форми, а не 1062');
        self::assertStringContainsString(' · ' . self::SLUG, (string) $this->tag('oleh-hryhorov'), 'бренд у назві для адмінки');

        $post = $this->post('validatsiia');
        $post->addTag((new ServicePostTag())->setSite($svc)->setSlugUk('chuzhyi')->setTitleUk('Чужий'));
        foreach (['a', 'b', 'c'] as $s) {
            $post->addTag((new ServicePostTag())->setSite($site)->setSlugUk($s)->setTitleUk($s));
        }
        $messages = array_map(static fn ($v): string => (string) $v->getMessage(), iterator_to_array($validator->validate($post)));
        self::assertContains('Не більше 3 тегів.', $messages);
        self::assertContains('Тег «Чужий · ' . $this->serviceSlug . '» — іншого бренду.', $messages);

        /** @var \App\ServiceSite\Redirect\SiteLiveUrls $live */
        $live = self::getContainer()->get(\App\ServiceSite\Redirect\SiteLiveUrls::class);
        $city = $this->em()->getRepository(ServiceCity::class)->findOneBy(['site' => $site]);
        self::assertTrue($live->isLive($site, $city, '/novyny/tag/oleh-hryhorov/'), 'активний тег — жива адреса');
        self::assertFalse($live->isLive($site, $city, '/novyny/tag/stara-nazva/'), 'старий слаг тегу — можна завести 301');
    }

    public function testInvalidTags(): void
    {
        $cases = [
            'кирилиця в слагу' => [['name' => 'Олег', 'slug' => 'Олег']],
            'слаг довший за 160' => [['name' => 'Довгий', 'slug' => str_repeat('a', 161)]],
            'новий тег без назви' => [['slug' => 'nevidomyi']],
            'більше 3 тегів' => [['name' => 'A', 'slug' => 'a'], ['name' => 'B', 'slug' => 'b'], ['name' => 'C', 'slug' => 'c'], ['name' => 'D', 'slug' => 'd']],
            'не масив' => 'oleh-hryhorov',
            'не список' => ['name' => 'Олег', 'slug' => 'oleh'],
        ];
        $n = 0;
        foreach ($cases as $label => $tags) {
            $r = $this->put('bad' . ++$n, sprintf('00000000-0000-4000-8000-0000000000e%d', $n), 'pohana-' . $n, ['tags' => $tags]);
            self::assertSame(422, $r->getStatusCode(), $label . ': ' . $r->getContent());
            self::assertArrayHasKey('tags', $this->json($r)['fields'] ?? [], $label);
        }
        // повтор слагу в списку — один тег, а не 422 і не дубль
        $dup = $this->put('dup', '00000000-0000-4000-8000-0000000000f1', 'dubl', ['tags' => [['name' => 'Олег', 'slug' => 'oleh'], ['name' => 'Олег', 'slug' => 'oleh']]]);
        self::assertSame(201, $dup->getStatusCode(), (string) $dup->getContent());
        self::assertSame(['oleh'], $this->json($dup)['tags'] ?? null);

        $site = $this->em()->find(ServiceSite::class, $this->siteId) ?? self::fail('бренд є');
        self::assertCount(1, $this->em()->getRepository(ServicePost::class)->findBy(['site' => $site]), 'з 422 стаття не створюється');
        self::assertSame(['oleh'], $this->tagSlugs(), 'з 422 теги не створюються');
    }

    public function testTagPage(): void
    {
        $oleh = ['name' => 'Олег Григоров', 'slug' => 'oleh-hryhorov'];
        $url = 'https://' . $this->host . '/novyny/tag/oleh-hryhorov/';
        self::assertSame(201, $this->put('p1', '00000000-0000-4000-8000-000000000101', 'novyna-1', ['tags' => [$oleh]])->getStatusCode());

        $page = $this->get($url);
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getContent();
        self::assertStringNotContainsString('noindex', $html, 'одна стаття — індексується (рішення власника 25.10.2026)');
        self::assertStringContainsString('<link rel="canonical" href="' . $url . '">', $html);
        self::assertStringContainsString('<h1>Олег Григоров</h1>', $html);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $html);
        self::assertStringContainsString('/novyny/novyna-1/', $html);
        self::assertStringContainsString('<loc>' . $url . '</loc>', $this->sitemap(), 'тег з першої статті — у sitemap');

        // тег без статей (стаття прибрала його) — сторінка є, але noindex, follow і поза sitemap
        $empty = 'https://' . $this->host . '/novyny/tag/bez-statei/';
        self::assertSame(201, $this->put('p0', '00000000-0000-4000-8000-000000000100', 'novyna-0', ['tags' => [['name' => 'Без статей', 'slug' => 'bez-statei']]])->getStatusCode());
        self::assertSame(200, $this->put('p0', '00000000-0000-4000-8000-000000000100', 'novyna-0', ['tags' => []])->getStatusCode());
        $html = (string) $this->get($empty)->getContent();
        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        self::assertStringNotContainsString($empty, $this->sitemap());

        // стаття показує тег посиланням і в keywords
        $post = (string) $this->get('https://' . $this->host . '/novyny/novyna-1/')->getContent();
        self::assertStringContainsString('<a href="/novyny/tag/oleh-hryhorov/" rel="tag">Олег Григоров</a>', $post);
        self::assertStringContainsString('"keywords":["Олег Григоров"]', $post);

        // три статті при 2 на сторінку (app.brands) — друга сторінка
        self::assertSame(201, $this->put('p2', '00000000-0000-4000-8000-000000000102', 'novyna-2', ['tags' => [$oleh]])->getStatusCode());
        self::assertSame(201, $this->put('p3', '00000000-0000-4000-8000-000000000103', 'novyna-3', ['tags' => [$oleh]])->getStatusCode());
        $html = (string) $this->get($url)->getContent();
        self::assertStringNotContainsString('noindex', $html);
        self::assertStringContainsString('<link rel="next" href="' . $url . 'page/2/">', $html);
        self::assertSame(200, $this->get($url . 'page/2/')->getStatusCode());
        $first = $this->get($url . 'page/1/');
        self::assertSame(301, $first->getStatusCode());
        self::assertSame('/novyny/tag/oleh-hryhorov/', $first->headers->get('Location'));
        self::assertSame(404, $this->get($url . 'page/3/')->getStatusCode());

        self::assertSame(404, $this->get('https://' . $this->host . '/novyny/tag/nevidomyi/')->getStatusCode());
        $this->em()->clear();
        $this->tag('oleh-hryhorov')->setActive(false);
        $this->em()->flush();
        self::assertSame(404, $this->get($url)->getStatusCode(), 'вимкнений тег');
        self::assertStringNotContainsString('rel="tag"', (string) $this->get('https://' . $this->host . '/novyny/novyna-1/')->getContent());
    }

    public function testServiceSiteIgnoresTags(): void
    {
        $r = $this->put('s1', '00000000-0000-4000-8000-000000000201', 'servisna', ['tags' => [['slug' => 'Невалідний']]], $this->serviceSlug);
        self::assertSame(201, $r->getStatusCode(), 'сервісний сайт: tags ігнорується, як і category_slug');
        self::assertSame([], $this->json($r)['tags'] ?? null);
        $svc = $this->em()->find(ServiceSite::class, $this->serviceId) ?? self::fail('сайт є');
        self::assertSame([], $this->em()->getRepository(ServicePostTag::class)->findBy(['site' => $svc]));
        self::assertSame(404, $this->get('https://' . $this->serviceSlug . '.example/blog/tag/servisna/')->getStatusCode(), 'сторінок тегів у сервісного сайту нема');
    }

    /** @param array<string, mixed> $extra lang у $extra перекриває uk */
    private function put(string $externalId, string $group, string $slug, array $extra, string $brand = self::SLUG): \Symfony\Component\HttpFoundation\Response
    {
        $body = (string) json_encode($extra + ['lang' => 'uk', 'group_id' => $group, 'title' => 'Новина ' . $slug, 'slug' => $slug, 'body_html' => '<p>«Пряма мова», — сказав він.</p>']);

        return $this->send('PUT', 'https://' . $this->admin . '/api/studio/' . $brand . '/articles/' . $externalId, $body, $this->auth);
    }

    private function get(string $url): \Symfony\Component\HttpFoundation\Response
    {
        return $this->send('GET', $url, '', []);
    }

    private function sitemap(): string
    {
        return (string) $this->get('https://' . $this->host . '/sitemap.xml')->getContent();
    }

    /** @return list<string> слаги тегів бренду в порядку створення */
    private function tagSlugs(): array
    {
        $this->em()->clear();
        $site = $this->em()->find(ServiceSite::class, $this->siteId) ?? self::fail('бренд є');

        return array_map(static fn (ServicePostTag $t): string => $t->slug('uk'), $this->em()->getRepository(ServicePostTag::class)->findBy(['site' => $site], ['id' => 'ASC']));
    }

    private function tag(string $slug): ServicePostTag
    {
        return $this->em()->getRepository(ServicePostTag::class)->findOneBy(['site' => $this->siteId, 'slugUk' => $slug]) ?? self::fail('тег ' . $slug);
    }

    private function post(string $slug): ServicePost
    {
        $this->em()->clear();

        return $this->em()->getRepository(ServicePost::class)->findOneBy(['site' => $this->siteId, 'slugUk' => $slug]) ?? self::fail('стаття ' . $slug);
    }

    /** @return list<string> */
    private function postTags(string $slug): array
    {
        return array_values(array_map(static fn (ServicePostTag $t): string => $t->slug('uk'), $this->post($slug)->getTags()->toArray()));
    }

    /** @return array<string, mixed> */
    private function json(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /** @param array<string, string> $server */
    private function send(string $method, string $url, string $body, array $server): \Symfony\Component\HttpFoundation\Response
    {
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $request = \Symfony\Component\HttpFoundation\Request::create($url, $method, [], [], [], $server, $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
