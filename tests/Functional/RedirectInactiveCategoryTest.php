<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServicePostCategory;
use App\Entity\ServiceSite;
use App\ServiceSite\Redirect\RedirectManager;
use App\ServiceSite\Redirect\SiteLiveUrls;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Редирект з адреси вимкненої рубрики (25.10.2026): /<блог>/category/<slug>/ приймає будь-який slug, тож адреса вимкненої
 * рубрики вважалась «живою» і менеджер редиректів (Admin API — 422) не давав завести 301, хоча сторінка віддає 404.
 * Жива — лише активна рубрика (сторінка, пагінація, RSS).
 */
final class RedirectInactiveCategoryTest extends HttpKernelTestCase
{
    private string $host;
    private int $siteId = 0;
    private int $extraSiteId = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $slug = 'ci-redir-cat-' . bin2hex(random_bytes(3));
        $this->host = $slug . '.example';
        $site = (new ServiceSite())->setSlug($slug)->setName('CI Redir')->setApexDomain($this->host)->setMode(ServiceSite::MODE_CONTENT);
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->host)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        $active = (new ServicePostCategory())->setSite($site)->setSlugUk('poradi')->setTitleUk('Поради');
        $em->persist($active);
        $em->persist((new ServicePostCategory())->setSite($site)->setSlugUk('stara')->setTitleUk('Стара')->setActive(false));
        $em->persist((new ServicePost())->setSite($site)->setSlugUk('persha')->setTitleUk('Перша')->setDescUk('<p>Текст</p>')
            ->setCategory($active)->setPublishedAt(new \DateTimeImmutable('-1 day')));
        $em->flush();
        $this->siteId = (int) $site->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $this->em()->getConnection()->executeStatement('DELETE FROM service_site WHERE id IN (?, ?)', [$this->siteId, $this->extraSiteId]);
        parent::tearDown();
    }

    public function testInactiveCategoryAddressIsNotLive(): void
    {
        $site = $this->em()->find(ServiceSite::class, $this->siteId) ?? self::fail('сайт є');
        $city = $this->em()->getRepository(ServiceCity::class)->findOneBy(['site' => $site]);
        /** @var SiteLiveUrls $live */
        $live = self::getContainer()->get(SiteLiveUrls::class);

        self::assertSame(404, $this->http('GET', 'https://' . $this->host . '/blog/category/stara/')->getStatusCode(), 'вимкнена рубрика — 404');
        // бренд з рубриками від кореня (ci-feed у when@test): стара адреса під розділом для вимкненої рубрики — теж 404, а не 301
        $rootHost = 'ci-feed-' . bin2hex(random_bytes(3)) . '.example';
        $root = (new ServiceSite())->setSlug('ci-feed')->setName('Root CI')->setApexDomain($rootHost)->setMode(ServiceSite::MODE_CONTENT)->setBlogPrefix('novyny');
        $this->em()->getConnection()->executeStatement('DELETE FROM service_site WHERE slug = ?', ['ci-feed']);
        $this->em()->persist($root);
        $this->em()->persist((new ServiceCity())->setSite($root)->setHost($rootHost)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        $this->em()->persist((new ServicePostCategory())->setSite($root)->setSlugUk('zakryta')->setTitleUk('Закрита')->setActive(false));
        $this->em()->persist((new ServicePostCategory())->setSite($root)->setSlugUk('zhyva')->setTitleUk('Жива'));
        $this->em()->flush();
        $this->extraSiteId = (int) $root->getId();
        foreach (['/novyny/category/zakryta/', '/novyny/category/zakryta/page/2/', '/novyny/category/zakryta/feed/'] as $path) {
            self::assertSame(404, $this->http('GET', 'https://' . $rootHost . $path)->getStatusCode(), $path . ' — вимкнена рубрика root-бренду: 404, не 301');
        }
        self::assertSame(301, $this->http('GET', 'https://' . $rootHost . '/novyny/category/zhyva/')->getStatusCode(), 'активна — 301 на /zhyva/, як було');
        foreach (['/blog/category/stara/', '/blog/category/stara/page/2/', '/blog/category/stara/feed/', '/blog/category/nemaye/'] as $path) {
            self::assertFalse($live->isLive($site, $city, $path), $path . ' — не жива, правило можна завести');
        }
        foreach (['/blog/category/poradi/', '/blog/category/poradi/feed/', '/blog/persha/'] as $path) {
            self::assertTrue($live->isLive($site, $city, $path), $path . ' — жива');
        }

        /** @var RedirectManager $redirects */
        $redirects = self::getContainer()->get(RedirectManager::class);
        self::assertNull($redirects->liveMessage($site, null, '/blog/category/stara/'), 'менеджер не блокує правило з адреси вимкненої рубрики');
        self::assertNotNull($redirects->liveMessage($site, null, '/blog/category/poradi/'), 'з адреси активної — блокує, як і раніше');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
