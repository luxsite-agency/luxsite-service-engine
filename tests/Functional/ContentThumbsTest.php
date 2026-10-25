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
 * Прев'ю зображень статей у списках (25.10.2026, ServiceSite.listThumbs — адмінка → бренд): off — мініатюр нема (типово);
 * home — лише головна; lists — стрічка, рубрика, тег, автор; all — скрізь. Стаття без обкладинки — без мініатюри.
 * Мініатюра — через Liip (item_hero_sm, webp), перша картка без lazy.
 */
final class ContentThumbsTest extends HttpKernelTestCase
{
    /** Фрагмент адреси мініатюри картки (Liip: /media/cache/resolve/item_hero_sm/… або /media/cache/item_hero_sm/…) */
    private const THUMB = '/item_hero_sm/media/service_post/';

    private string $host;
    private int $siteId = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $slug = 'ci-thumbs-' . bin2hex(random_bytes(3));
        $this->host = $slug . '.example';
        $site = (new ServiceSite())->setSlug($slug)->setName('CI Thumbs')->setApexDomain($this->host)->setMode(ServiceSite::MODE_CONTENT);
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->host)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        $cat = (new ServicePostCategory())->setSite($site)->setSlugUk('poradi')->setTitleUk('Поради');
        $em->persist($cat);
        $posts = [['z-foto-1', 'cover-1.jpg', '-1 hour'], ['bez-foto', null, '-2 hours'], ['z-foto-2', 'cover-2.jpg', '-3 hours']];
        foreach ($posts as [$s, $image, $when]) {
            $em->persist((new ServicePost())->setSite($site)->setSlugUk($s)->setTitleUk('Стаття ' . $s)->setDescUk('<p>Текст</p>')
                ->setCategory($cat)->setImageName($image)->setImageAlt($image !== null ? 'Опис ' . $s : null)->setPublishedAt(new \DateTimeImmutable($when)));
        }
        $em->flush();
        $this->siteId = (int) $site->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM service_site WHERE id = ?', [$this->siteId]);
        parent::tearDown();
    }

    public function testSwitchControlsThumbnails(): void
    {
        // типово вимкнено: мініатюр нема ні на головній, ні в списках
        self::assertSame(ServiceSite::THUMBS_OFF, $this->site()->getListThumbs());
        foreach (['/', '/blog/', '/blog/category/poradi/'] as $path) {
            self::assertSame(0, $this->thumbs($path), "$path — вимкнено");
        }

        $this->switchTo(ServiceSite::THUMBS_HOME);
        self::assertSame(2, $this->thumbs('/'), 'головна — лише 2 статті з обкладинкою');
        self::assertSame(0, $this->thumbs('/blog/'));
        self::assertSame(0, $this->thumbs('/blog/category/poradi/'));

        $this->switchTo(ServiceSite::THUMBS_LISTS);
        self::assertSame(0, $this->thumbs('/'));
        self::assertSame(2, $this->thumbs('/blog/'));
        self::assertSame(2, $this->thumbs('/blog/category/poradi/'));

        $this->switchTo(ServiceSite::THUMBS_ALL);
        $html = $this->page('/blog/');
        self::assertSame(2, substr_count($html, self::THUMB));
        self::assertSame(2, $this->thumbs('/'));
        // перша мініатюра — без lazy (над першим екраном), решта — lazy; alt з обкладинки; розміри проти CLS
        self::assertMatchesRegularExpression('~<img src="[^"]*' . preg_quote(self::THUMB, '~') . 'cover-1\.jpg" alt="Опис z-foto-1" width="460" height="307">~u', $html);
        self::assertMatchesRegularExpression('~<img src="[^"]*' . preg_quote(self::THUMB, '~') . 'cover-2\.jpg" alt="Опис z-foto-2" width="460" height="307" loading="lazy">~u', $html);
        self::assertStringNotContainsString('src="/media/service_post/cover-1.jpg"', $html, 'оригінал у списку не віддаємо');
    }

    public function testInvalidValueRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->site()->setListThumbs('sometimes');
    }

    private function switchTo(string $value): void
    {
        $site = $this->site()->setListThumbs($value);
        $this->em()->flush();
        $this->em()->clear();
        self::assertSame($value, $site->getListThumbs());
    }

    private function thumbs(string $path): int
    {
        return substr_count($this->page($path), self::THUMB);
    }

    private function page(string $path): string
    {
        $response = $this->http('GET', 'https://' . $this->host . $path);
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getContent();
    }

    private function site(): ServiceSite
    {
        return $this->em()->find(ServiceSite::class, $this->siteId) ?? self::fail('бренд є');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
