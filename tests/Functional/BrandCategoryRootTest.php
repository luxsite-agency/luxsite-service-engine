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
 * Рубрики від кореня (24.10.2026, app.brands.ci-catroot.category_url: root у when@test): рубрика бренду — /<slug>/ і
 * /<slug>/page/N/, стара /novyny/category/<slug>/ — один 301; посилання, canonical, sitemap — нові адреси. Сусід без
 * опції — як був. Невідомий /<x>/ і фіксовані розділи рубрикою не стають; рубрика tvi «news» — можна.
 */
final class BrandCategoryRootTest extends HttpKernelTestCase
{
    /** @var list<int> */
    private array $siteIds = [];
    private string $root = '';
    private string $plain = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $conn = $em->getConnection();
        $conn->executeStatement("DELETE FROM service_site WHERE slug = 'ci-catroot'"); // після обірваного прогону
        $suffix = bin2hex(random_bytes(3));
        foreach (['ci-catroot', 'ci-catplain-' . $suffix] as $slug) {
            $site = (new ServiceSite())->setSlug($slug)->setName($slug)->setApexDomain($slug . '-' . $suffix . '.example')->setMode(ServiceSite::MODE_CONTENT)->setBlogPrefix('novyny');
            $em->persist($site);
            $em->persist((new ServiceCity())->setSite($site)->setHost($slug . '-' . $suffix . '.example')->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
            foreach (['sport' => 21, 'news' => 1] as $cs => $n) {
                $cat = (new ServicePostCategory())->setSite($site)->setSlugUk($cs)->setTitleUk('Рубрика ' . $cs);
                $em->persist($cat);
                for ($i = 1; $i <= $n; ++$i) {
                    $em->persist((new ServicePost())->setSite($site)->setSlugUk($cs . '-' . $i)->setTitleUk('Стаття ' . $i)->setDescUk('<p>Т</p>')
                        ->setCategory($cat)->setActive(true)->setPublishedAt(new \DateTimeImmutable('-' . $i . ' hours')));
                }
            }
            $em->flush();
            $this->siteIds[] = (int) $site->getId();
            $slug === 'ci-catroot' ? $this->root = 'https://' . $slug . '-' . $suffix . '.example' : $this->plain = 'https://' . $slug . '-' . $suffix . '.example';
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

    public function testCategoriesLiveAtRoot(): void
    {
        $r = $this->root;
        self::assertSame(200, $this->http('GET', $r . '/sport/')->getStatusCode());
        self::assertSame(200, $this->http('GET', $r . '/news/')->getStatusCode(), 'рубрика «news» — у бренду з розділом novyny');
        self::assertSame(200, $this->http('GET', $r . '/sport/page/2/')->getStatusCode(), '21 стаття при 12 на сторінку');
        foreach (['/sport/page/1/' => '/sport/', '/novyny/category/sport/' => '/sport/', '/novyny/category/sport/page/2/' => '/sport/page/2/', '/novyny/category/sport/page/1/' => '/sport/'] as $from => $to) {
            $resp = $this->http('GET', $r . $from);
            self::assertSame(301, $resp->getStatusCode(), $from);
            self::assertSame($to, $resp->headers->get('Location'), $from . ' — один 301');
        }
        self::assertSame(404, $this->http('GET', $r . '/nemaye/')->getStatusCode(), 'невідомий сегмент — не рубрика');
        self::assertSame(200, $this->http('GET', $r . '/contacts/')->getStatusCode(), 'фіксований розділ на місці');

        $cat = (string) $this->http('GET', $r . '/sport/')->getContent();
        self::assertStringContainsString('<link rel="canonical" href="' . $r . '/sport/"', $cat);
        self::assertStringContainsString('href="/sport/page/2/"', $cat, 'пагінація від кореня');
        $post = (string) $this->http('GET', $r . '/novyny/sport-1/')->getContent();
        self::assertStringContainsString('href="/sport/"', $post, 'посилання рубрики зі статті');
        self::assertStringContainsString('"' . $r . '/sport/"', $post, 'хлібні крихти (JSON-LD)');
        self::assertStringNotContainsString('/category/', $post);
        $sitemap = (string) $this->http('GET', $r . '/sitemap.xml')->getContent();
        self::assertStringContainsString('<loc>' . $r . '/sport/</loc>', $sitemap);
        self::assertStringNotContainsString('/category/', $sitemap);
    }

    public function testBrandWithoutOptionUnchanged(): void
    {
        $p = $this->plain;
        self::assertSame(404, $this->http('GET', $p . '/sport/')->getStatusCode());
        self::assertSame(200, $this->http('GET', $p . '/novyny/category/sport/')->getStatusCode());
        self::assertStringContainsString('href="/novyny/category/sport/"', (string) $this->http('GET', $p . '/novyny/sport-1/')->getContent());
    }

    public function testReservedSlugRejected(): void
    {
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $em = $this->em();
        $root = $em->find(ServiceSite::class, $this->siteIds[0]);
        $plain = $em->find(ServiceSite::class, $this->siteIds[1]);
        self::assertNotNull($root);
        self::assertNotNull($plain);
        foreach (['about' => 1, 'novyny' => 1, 'contacts' => 1, 'news' => 0, 'blog' => 0, 'sport-2' => 0] as $slug => $errors) {
            self::assertCount($errors, $validator->validate((new ServicePostCategory())->setSite($root)->setSlugUk($slug)->setTitleUk('x')), $slug);
        }
        self::assertCount(0, $validator->validate((new ServicePostCategory())->setSite($plain)->setSlugUk('about')->setTitleUk('x')), 'без опції — як було');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
