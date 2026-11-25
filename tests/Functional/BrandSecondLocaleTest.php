<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServicePostCategory;
use App\Entity\ServiceSite;
use App\Tests\Support\SecondLocaleKernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Друга мова окремому бренду (25.11.2026) у розгортанні без префіксів: бренд uk + ru живе на /… і /ru/…, сусід з однією
 * мовою — лише на /…, його адреси не змінюються.
 */
final class BrandSecondLocaleTest extends HttpKernelTestCase
{
    /** @var list<int> */
    private array $siteIds = [];
    private string $two = '';
    private string $one = '';
    private int $postId = 0;

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new SecondLocaleKernel('test', true);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        foreach (['two' => ['uk', 'ru'], 'one' => ['uk']] as $name => $locales) {
            $slug = 'ci-' . $name . '-' . $suffix;
            $site = (new ServiceSite())->setSlug($slug)->setName('CI ' . $name)->setApexDomain($slug . '.example')->setMode(ServiceSite::MODE_CONTENT)->setLocales($locales);
            $em->persist($site);
            $em->persist((new ServiceCity())->setSite($site)->setHost($slug . '.example')->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
            $cat = (new ServicePostCategory())->setSite($site)->setSlugUk('rubryka')->setTitleUk('Рубрика')->setSlugRu('rubrika')->setTitleRu('Рубрика ru');
            $em->persist($cat);
            $post = (new ServicePost())->setSite($site)->setSlugUk('stattia')->setTitleUk('Стаття')->setDescUk('<p>Текст</p>')
                ->setSlugRu('statia')->setTitleRu('Статья')->setDescRu('<p>Текст по-русски</p>')
                ->setCategory($cat)->setActive(true)->setPublishedAt(new \DateTimeImmutable('-1 day'));
            $em->persist($post);
            $em->flush();
            $this->siteIds[] = (int) $site->getId();
            $this->{$name} = 'https://' . $slug . '.example';
            if ($name === 'two') {
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

    public function testSecondLocaleOnlyForBrandThatEnabledIt(): void
    {
        foreach (['/', '/blog/', '/blog/stattia/', '/ru/', '/ru/blog/', '/ru/blog/statia/', '/ru/blog/category/rubrika/'] as $path) {
            self::assertSame(200, $this->http('GET', $this->two . $path)->getStatusCode(), 'two ' . $path);
        }
        foreach (['/', '/blog/', '/blog/stattia/'] as $path) {
            self::assertSame(200, $this->http('GET', $this->one . $path)->getStatusCode(), 'one ' . $path);
        }
        foreach (['/ru/', '/ru/blog/', '/ru/blog/statia/', '/uk/'] as $path) {
            self::assertSame(404, $this->http('GET', $this->one . $path)->getStatusCode(), 'one ' . $path);
        }
        self::assertSame(404, $this->http('GET', $this->two . '/uk/')->getStatusCode(), 'мова сайту — лише без префікса');

        $uk = (string) $this->http('GET', $this->two . '/blog/stattia/')->getContent();
        self::assertStringContainsString('<link rel="canonical" href="' . $this->two . '/blog/stattia/"', $uk);
        self::assertStringContainsString('href="' . $this->two . '/ru/blog/statia/"', $uk, 'hreflang на другу мову');
        $ru = (string) $this->http('GET', $this->two . '/ru/blog/statia/')->getContent();
        self::assertStringContainsString('<html lang="ru"', $ru);
        self::assertStringContainsString('<link rel="canonical" href="' . $this->two . '/ru/blog/statia/"', $ru);
        self::assertStringContainsString('href="' . $this->two . '/blog/stattia/"', $ru, 'hreflang на мову сайту');
        self::assertStringContainsString('/ru/blog/', $ru);

        $sitemap = (string) $this->http('GET', $this->two . '/sitemap.xml')->getContent();
        self::assertStringContainsString($this->two . '/blog/stattia/', $sitemap);
        self::assertStringContainsString($this->two . '/ru/blog/statia/', $sitemap);
        self::assertStringNotContainsString('/ru/', (string) $this->http('GET', $this->one . '/sitemap.xml')->getContent());
        self::assertStringNotContainsString('/ru/', (string) $this->http('GET', $this->one . '/blog/stattia/')->getContent());
    }

    public function testSlugChangeRedirectsUnderPrefix(): void
    {
        $em = $this->em();
        $post = $em->find(ServicePost::class, $this->postId);
        self::assertNotNull($post);
        $post->setSlugRu('novaia');
        $em->flush();
        $r = $this->http('GET', $this->two . '/ru/blog/statia/');
        self::assertSame(301, $r->getStatusCode());
        self::assertSame($this->two . '/ru/blog/novaia/', $r->headers->get('Location'));
        self::assertSame(200, $this->http('GET', $this->two . '/blog/stattia/')->getStatusCode());
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
