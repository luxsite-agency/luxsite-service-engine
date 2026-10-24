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
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Розділ статей на бренд (24.10.2026): бренд з ServiceSite.blogPrefix «novyny» живе на /novyny/…, сусід без поля —
 * на app.blog_prefix (/blog/…). Чужий розділ на хості бренду — 404; посилання сторінок, sitemap і автоправила 301
 * при зміні slug — з розділом свого бренду.
 */
final class BrandBlogPrefixTest extends HttpKernelTestCase
{
    /** @var list<int> */
    private array $siteIds = [];
    private string $news = '';
    private string $plain = '';
    private int $postId = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        foreach (['news' => 'novyny', 'plain' => null] as $name => $prefix) {
            $slug = 'ci-' . $name . '-' . $suffix;
            $site = (new ServiceSite())->setSlug($slug)->setName('CI ' . $name)->setApexDomain($slug . '.example')->setMode(ServiceSite::MODE_CONTENT)->setBlogPrefix($prefix);
            $em->persist($site);
            $em->persist((new ServiceCity())->setSite($site)->setHost($slug . '.example')->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
            $cat = (new ServicePostCategory())->setSite($site)->setSlugUk('rubryka')->setTitleUk('Рубрика');
            $em->persist($cat);
            $post = (new ServicePost())->setSite($site)->setSlugUk('stattia-' . $name)->setTitleUk('Стаття ' . $name)->setDescUk('<p>Текст</p>')
                ->setCategory($cat)->setActive(true)->setPublishedAt(new \DateTimeImmutable('-1 day'));
            $em->persist($post);
            $em->flush();
            $this->siteIds[] = (int) $site->getId();
            $this->{$name} = $slug . '.example';
            if ($name === 'news') {
                $this->postId = (int) $post->getId();
            }
        }
        $em->clear();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        foreach ($this->siteIds as $id) {
            $conn->executeStatement('DELETE FROM service_site WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    public function testEachBrandServesOnlyItsOwnSection(): void
    {
        $news = 'https://' . $this->news;
        $plain = 'https://' . $this->plain;
        foreach (['/novyny/', '/novyny/stattia-news/', '/novyny/category/rubryka/'] as $path) {
            self::assertSame(200, $this->http('GET', $news . $path)->getStatusCode(), 'news ' . $path);
        }
        foreach (['/blog/', '/blog/stattia-news/', '/blog/category/rubryka/'] as $path) {
            self::assertSame(404, $this->http('GET', $news . $path)->getStatusCode(), 'news ' . $path . ' — розділ сусіда');
        }
        self::assertSame(200, $this->http('GET', $plain . '/blog/stattia-plain/')->getStatusCode(), 'без поля — app.blog_prefix');
        self::assertSame(404, $this->http('GET', $plain . '/novyny/stattia-plain/')->getStatusCode());
        self::assertSame(301, $this->http('GET', $news . '/novyny/page/1/')->getStatusCode());
        self::assertSame('/novyny/', $this->http('GET', $news . '/novyny/page/1/')->headers->get('Location'));

        $home = (string) $this->http('GET', $news . '/')->getContent();
        self::assertStringContainsString('/novyny/stattia-news/', $home, 'посилання на статтю — з розділом бренду');
        self::assertStringNotContainsString('href="/blog/', $home);
        $post = (string) $this->http('GET', $news . '/novyny/stattia-news/')->getContent();
        self::assertStringContainsString('<link rel="canonical" href="' . $news . '/novyny/stattia-news/"', $post);
        self::assertStringContainsString('/novyny/category/rubryka/', $post);
        $sitemap = (string) $this->http('GET', $news . '/sitemap.xml')->getContent();
        self::assertStringContainsString($news . '/novyny/stattia-news/', $sitemap);
        self::assertStringNotContainsString('/blog/', $sitemap);
        self::assertStringContainsString('/blog/stattia-plain/', (string) $this->http('GET', $plain . '/')->getContent(), 'сусід — як і був');
    }

    public function testSlugChangeRedirectsWithinBrandSection(): void
    {
        $em = $this->em();
        $post = $em->find(ServicePost::class, $this->postId);
        self::assertNotNull($post);
        $post->setSlugUk('stattia-nova');
        $em->flush();
        $r = $this->http('GET', 'https://' . $this->news . '/novyny/stattia-news/');
        self::assertSame(301, $r->getStatusCode());
        self::assertSame('https://' . $this->news . '/novyny/stattia-nova/', $r->headers->get('Location'));
    }

    public function testPrefixValidation(): void
    {
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $site = (new ServiceSite())->setSlug('ci-x')->setName('x')->setApexDomain('x.example');
        self::assertCount(0, $validator->validate($site->setBlogPrefix('/novyny/')), 'слеші обрізаються');
        self::assertSame('novyny', $site->getBlogPrefix());
        self::assertNull($site->setBlogPrefix(' ')->getBlogPrefix(), 'порожньо — app.blog_prefix');
        self::assertCount(1, $validator->validate($site->setBlogPrefix('Новини')));
        self::assertCount(1, $validator->validate($site->setBlogPrefix('services')), 'сегмент каталогу');
        self::assertCount(0, $validator->validate($site->setBlogPrefix('news')));
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
