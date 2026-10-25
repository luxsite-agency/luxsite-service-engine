<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Doctrine\PostContentDateListener;
use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Дата змістовного оновлення статті (25.10.2026): імпорт і технічні записи не зсувають dateModified (= дата публікації);
 * типографіка, розмітка, обкладинка — теж; змінений по суті текст — так. «Updated» — лише коли оновлення пізніше за публікацію
 * більш ніж на добу.
 */
final class PostContentDatesTest extends HttpKernelTestCase
{
    private string $host;
    private int $siteId = 0;
    private int $postId = 0;
    private \DateTimeImmutable $published;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $slug = 'ci-dates-' . bin2hex(random_bytes(3));
        $this->host = $slug . '.example';
        $site = (new ServiceSite())->setSlug($slug)->setName('CI Dates')->setApexDomain($this->host)->setMode(ServiceSite::MODE_CONTENT);
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->host)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        $this->published = new \DateTimeImmutable('-30 days 10:00');
        $post = (new ServicePost())->setSite($site)->setSlugUk('stattia')->setTitleUk('Стаття — про ремонт')
            ->setDescUk('<p>Перше речення — про фарбу. Друге «про стіни».</p>')->setPublishedAt($this->published);
        $em->persist($post);
        $em->flush();
        $this->siteId = (int) $site->getId();
        $this->postId = (int) $post->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM service_site WHERE id = ?', [$this->siteId]);
        parent::tearDown();
    }

    public function testImportAndTypographyDoNotMoveDateModified(): void
    {
        self::assertNull($this->post()->getContentUpdatedAt(), 'створення (імпорт) — без дати оновлення');
        self::assertSame($this->published->format('c'), $this->dateModified(), 'dateModified = дата публікації');

        // типографіка й розмітка (тире → дефіс, лапки, nbsp, інший тег), обкладинка — не зміст
        $this->post()->setTitleUk('Стаття - про ремонт')->setDescUk('<div>Перше речення&nbsp;- про фарбу.  Друге "про стіни".</div>')
            ->setImageName('cover.jpg');
        $this->em()->flush();
        $this->em()->clear();
        self::assertNull($this->post()->getContentUpdatedAt(), 'типографіка не оновлює дату');
        self::assertSame($this->published->format('c'), $this->dateModified());
        self::assertFalse($this->post()->isUpdated());

        // зміст по суті — новий абзац
        $this->post()->setDescUk('<p>Перше речення — про фарбу. Друге «про стіни». Третє — про ціни 2026 року.</p>');
        $this->em()->flush();
        $this->em()->clear();
        $post = $this->post();
        self::assertNotNull($post->getContentUpdatedAt(), 'змістовна правка — дата оновлення');
        self::assertLessThan(60, abs(time() - $post->getContentUpdatedAt()->getTimestamp()));
        self::assertTrue($post->isUpdated(), 'оновлено через 30 днів після публікації');
        self::assertSame($post->getContentUpdatedAt()->format('c'), $this->dateModified());
        // sitemap: lastmod статті — теж дата змістовного оновлення, а не публікації
        $sitemap = (string) $this->http('GET', 'https://' . $this->host . '/sitemap.xml')->getContent();
        self::assertMatchesRegularExpression('~/blog/stattia/</loc>\s*<lastmod>' . $post->getContentUpdatedAt()->format('Y-m-d') . '</lastmod>~', $sitemap);
    }

    public function testModifiedAtAndNormalize(): void
    {
        $p = (new ServicePost())->setPublishedAt(new \DateTimeImmutable('2026-10-01 10:00'));
        self::assertSame('2026-10-01', $p->modifiedAt()?->format('Y-m-d'));
        $p->setContentUpdatedAt(new \DateTimeImmutable('2026-10-01 20:00'));
        self::assertSame('2026-10-01 20:00', $p->modifiedAt()?->format('Y-m-d H:i'));
        self::assertFalse($p->isUpdated(), 'того самого дня — без «Updated»');
        $p->setContentUpdatedAt(new \DateTimeImmutable('2026-09-01'));
        self::assertSame('2026-10-01', $p->modifiedAt()?->format('Y-m-d'), 'оновлення раніше за публікацію — дата публікації');
        self::assertSame(PostContentDateListener::normalize('<p>Тест — «так»&nbsp;Ok</p>'), PostContentDateListener::normalize('Тест - "так" ok'));
        self::assertSame(PostContentDateListener::normalize("\u{0439}"), PostContentDateListener::normalize("\u{0438}\u{0306}"), 'й у NFC і NFD — те саме');
        self::assertNotSame(PostContentDateListener::normalize("a\xff"), PostContentDateListener::normalize("b\xff"), 'невалідний UTF-8 не зводиться до порожнього рядка');
    }

    private function dateModified(): string
    {
        $html = (string) $this->http('GET', 'https://' . $this->host . '/blog/stattia/')->getContent();
        self::assertSame(1, preg_match('~"dateModified":"([^"]+)"~', $html, $m), 'dateModified у JSON-LD');

        return $m[1];
    }

    private function post(): ServicePost
    {
        return $this->em()->find(ServicePost::class, $this->postId) ?? self::fail('стаття є');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
