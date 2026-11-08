<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use Doctrine\ORM\EntityManagerInterface;

/** Дефолт рушія (app.hreflang_single: false): одномовний бренд, як і до 08.11.2026, — без hreflang у <head> і sitemap.xml. */
final class HreflangDefaultTest extends HttpKernelTestCase
{
    private int $siteId = 0;
    private string $host = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $slug = 'ci-hd-' . bin2hex(random_bytes(3));
        $this->host = $slug . '.example';
        $site = (new ServiceSite())->setSlug($slug)->setName('CI Hreflang')->setApexDomain($this->host)
            ->setMode(ServiceSite::MODE_CONTENT)->setLocales(['uk'])->setAboutUk('<p>Про видання.</p>');
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->host)->setSlug('main')->setIsApex(true)->setNameNomUk('CI'));
        $em->persist((new ServicePost())->setSite($site)->setSlugUk('persha')->setTitleUk('Перша')->setDescUk('<p>Текст.</p>')
            ->setPublishedAt(new \DateTimeImmutable('-1 day')));
        $em->flush();
        $this->siteId = (int) $site->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $conn = $this->em()->getConnection();
        $conn->executeStatement('DELETE FROM service_post WHERE site_id = ?', [$this->siteId]);
        $conn->executeStatement('DELETE FROM service_city WHERE site_id = ?', [$this->siteId]);
        $conn->executeStatement('DELETE FROM service_site WHERE id = ?', [$this->siteId]);
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSingleLanguageBrandHasNoHreflangByDefault(): void
    {
        $base = 'https://' . $this->host;
        foreach (['/', '/blog/persha/'] as $path) {
            self::assertStringNotContainsString('rel="alternate" hreflang=', (string) $this->http('GET', $base . $path)->getContent(), $path);
        }
        self::assertStringNotContainsString('xhtml:link', (string) $this->http('GET', $base . '/sitemap.xml')->getContent());
    }
}
