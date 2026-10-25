<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServicePostCategory;
use App\Entity\ServiceSite;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RSS контентного сайту (25.10.2026): /feed/ і стрічка рубрики (/<рубрика>/feed/ при category_url: root — бренд ci-feed у
 * when@test; інакше /<блог>/category/<рубрика>/feed/). Валідний XML, канонічні посилання, стабільний guid, RFC 822, обкладинка;
 * <link rel="alternate"> у <head>; сервісний сайт RSS не має.
 */
final class FeedTest extends HttpKernelTestCase
{
    private const ROOT = 'ci-feed';

    private string $rootHost;
    private string $plainHost;
    private string $svcHost;
    /** @var list<int> */
    private array $siteIds = [];
    private string $cover = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $em->getConnection()->executeStatement('DELETE FROM service_site WHERE slug = ?', [self::ROOT]);
        $suffix = bin2hex(random_bytes(3));
        $this->rootHost = self::ROOT . '-' . $suffix . '.example';
        $this->plainHost = 'ci-feed-plain-' . $suffix . '.example';
        $this->svcHost = 'ci-feed-svc-' . $suffix . '.example';

        $root = (new ServiceSite())->setSlug(self::ROOT)->setName('Новини CI')->setApexDomain($this->rootHost)->setMode(ServiceSite::MODE_CONTENT)->setBlogPrefix('novyny');
        $plain = (new ServiceSite())->setSlug('ci-feed-plain-' . $suffix)->setName('Журнал CI')->setApexDomain($this->plainHost)->setMode(ServiceSite::MODE_CONTENT);
        $svc = (new ServiceSite())->setSlug('ci-feed-svc-' . $suffix)->setName('Сервіс CI')->setApexDomain($this->svcHost);
        foreach ([[$root, $this->rootHost], [$plain, $this->plainHost], [$svc, $this->svcHost]] as [$site, $host]) {
            $em->persist($site);
            $em->persist((new ServiceCity())->setSite($site)->setHost($host)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        }
        $pol = (new ServicePostCategory())->setSite($root)->setSlugUk('polityka')->setTitleUk('Політика');
        $eco = (new ServicePostCategory())->setSite($root)->setSlugUk('ekonomika')->setTitleUk('Економіка');
        $por = (new ServicePostCategory())->setSite($plain)->setSlugUk('poradi')->setTitleUk('Поради');
        foreach ([$pol, $eco, $por] as $c) {
            $em->persist($c);
        }
        $name = 'ci-feed-' . $suffix . '.jpg';
        $this->cover = self::getContainer()->getParameter('kernel.project_dir') . '/public/media/service_post/' . $name;
        @mkdir(\dirname($this->cover), 0775, true);
        file_put_contents($this->cover, str_repeat('x', 1234));
        $em->persist((new ServicePost())->setSite($root)->setSlugUk('zaiava-uriadu')->setTitleUk('Уряд & громади: заява')->setLeadUk('<p>Лід <b>новини</b> про уряд.</p>')
            ->setDescUk('<p>Текст</p>')->setCategory($pol)->setImageName($name)->setPublishedAt(new \DateTimeImmutable('-1 hour')));
        $em->persist((new ServicePost())->setSite($root)->setSlugUk('kurs-hryvni')->setTitleUk('Курс гривні')->setDescUk('<p>Текст без ліду про курс.</p>')
            ->setCategory($eco)->setPublishedAt(new \DateTimeImmutable('-2 hours')));
        $em->persist((new ServicePost())->setSite($root)->setSlugUk('chernetka')->setTitleUk('Чернетка')->setDescUk('<p>Ще не вийшла</p>')
            ->setCategory($pol)->setPublishedAt(new \DateTimeImmutable('+1 day')));
        // керівний символ із Word у заголовку й злиті блоки в ліді — стрічка лишається валідною, слова не злипаються
        $em->persist((new ServicePost())->setSite($plain)->setSlugUk('porada')->setTitleUk("Порада\x0B дня")
            ->setLeadUk('<p>Перше речення.</p><p>Друге <a href="/x/">посилання</a>, кома.</p><script>alert(1)</script>')->setDescUk('<p>Текст</p>')
            ->setCategory($por)->setPublishedAt(new \DateTimeImmutable('-1 day')));
        $em->flush();
        $this->siteIds = [(int) $root->getId(), (int) $plain->getId(), (int) $svc->getId()];
        $em->clear();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        foreach ($this->siteIds as $id) {
            $conn->executeStatement('DELETE FROM service_site WHERE id = ?', [$id]);
        }
        @unlink($this->cover);
        parent::tearDown();
    }

    public function testSiteFeed(): void
    {
        $r = $this->http('GET', 'https://' . $this->rootHost . '/feed/');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('application/rss+xml; charset=UTF-8', $r->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=300', (string) $r->headers->get('Cache-Control'));
        self::assertStringContainsString('public', (string) $r->headers->get('Cache-Control'));
        $xml = $this->xml((string) $r->getContent());
        $base = 'https://' . $this->rootHost;

        self::assertSame('Новини CI', (string) $xml->channel->title);
        self::assertSame($base . '/', (string) $xml->channel->link);
        self::assertSame('uk', (string) $xml->channel->language);
        self::assertSame($base . '/feed/', (string) $xml->channel->children('http://www.w3.org/2005/Atom')->link->attributes()['href']);
        self::assertCount(2, $xml->channel->item, 'чернетка з майбутнього — поза стрічкою');

        $first = $xml->channel->item[0];
        self::assertSame('Уряд & громади: заява', (string) $first->title);
        self::assertSame($base . '/novyny/zaiava-uriadu/', (string) $first->link);
        self::assertMatchesRegularExpression('~^' . preg_quote($this->rootHost, '~') . '-post-\d+$~', (string) $first->guid);
        self::assertSame('false', (string) $first->guid->attributes()['isPermaLink']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_RSS, (string) $first->pubDate), 'pubDate — RFC 822');
        self::assertSame('Лід новини про уряд.', (string) $first->description, 'лід без HTML');
        self::assertSame('Політика', (string) $first->category);
        $enc = $first->enclosure->attributes();
        self::assertStringEndsWith('/media/service_post/' . basename($this->cover), (string) $enc['url']);
        self::assertSame('1234', (string) $enc['length']);
        self::assertSame('image/jpeg', (string) $enc['type']);
        self::assertSame((string) $enc['url'], (string) $first->children('http://search.yahoo.com/mrss/')->content->attributes()['url']);

        $second = $xml->channel->item[1];
        self::assertSame('Текст без ліду про курс.', (string) $second->description, 'без ліду — початок тексту');
        self::assertCount(0, $second->enclosure, 'без обкладинки — без enclosure');
    }

    public function testCategoryFeedsAndHeadLinks(): void
    {
        $base = 'https://' . $this->rootHost;
        $cat = $this->http('GET', $base . '/polityka/feed/');
        self::assertSame(200, $cat->getStatusCode(), 'рубрика від кореня');
        $xml = $this->xml((string) $cat->getContent());
        self::assertSame('Новини CI: Політика', (string) $xml->channel->title);
        self::assertSame($base . '/polityka/', (string) $xml->channel->link);
        self::assertCount(1, $xml->channel->item);
        self::assertSame($base . '/polityka/feed/', (string) $xml->channel->children('http://www.w3.org/2005/Atom')->link->attributes()['href']);

        $old = $this->http('GET', $base . '/novyny/category/polityka/feed/');
        self::assertSame(301, $old->getStatusCode(), 'стара адреса під розділом — один 301');
        self::assertSame('/polityka/feed/', $old->headers->get('Location'));
        self::assertSame(404, $this->http('GET', $base . '/nemaye/feed/')->getStatusCode());

        $plain = $this->http('GET', 'https://' . $this->plainHost . '/blog/category/poradi/feed/');
        self::assertSame(200, $plain->getStatusCode(), 'рубрика під розділом статей');
        $plainXml = $this->xml((string) $plain->getContent());
        self::assertCount(1, $plainXml->channel->item);
        self::assertSame('Порада дня', (string) $plainXml->channel->item[0]->title, 'керівний символ прибрано');
        self::assertSame('Перше речення. Друге посилання, кома.', (string) $plainXml->channel->item[0]->description, 'без злиплих слів і без script');

        $home = (string) $this->http('GET', $base . '/')->getContent();
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml" title="Новини CI" href="' . $base . '/feed/">', $home);
        $page = (string) $this->http('GET', $base . '/polityka/')->getContent();
        self::assertStringContainsString('<link rel="alternate" type="application/rss+xml" title="Новини CI: Політика" href="' . $base . '/polityka/feed/">', $page);

        self::assertSame(404, $this->http('GET', 'https://' . $this->svcHost . '/feed/')->getStatusCode(), 'сервісний сайт RSS не має');
        // менеджер редиректів: /feed/ сервісного сайту — не «жива» адреса (на неї можна завести 301/410), контентного — жива
        /** @var \App\ServiceSite\Redirect\SiteLiveUrls $live */
        $live = self::getContainer()->get(\App\ServiceSite\Redirect\SiteLiveUrls::class);
        [$rootId, , $svcId] = $this->siteIds;
        $svc = $this->em()->find(ServiceSite::class, $svcId) ?? self::fail('сайт є');
        $root = $this->em()->find(ServiceSite::class, $rootId) ?? self::fail('бренд є');
        self::assertFalse($live->isLive($svc, $this->em()->getRepository(ServiceCity::class)->findOneBy(['site' => $svc]), '/feed/'));
        self::assertTrue($live->isLive($root, $this->em()->getRepository(ServiceCity::class)->findOneBy(['site' => $root]), '/feed/'));
        self::assertStringNotContainsString('application/rss+xml', (string) $this->http('GET', 'https://' . $this->svcHost . '/')->getContent());
    }

    private function xml(string $body): \SimpleXMLElement
    {
        self::assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $body);
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        self::assertNotFalse($xml, 'валідний XML: ' . implode('; ', array_map(static fn ($e) => trim($e->message), $errors)));

        return $xml;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
