<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use App\Tests\Support\HreflangSingleKernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * hreflang одномовного бренду (08.11.2026): з app.hreflang_single сторінка посилається сама на себе + x-default, sitemap.xml —
 * ті самі коди; без параметра (дефолт рушія) — як було, без hreflang. На 404 hreflang нема.
 */
final class HreflangSingleTest extends HttpKernelTestCase
{
    private int $siteId = 0;
    private string $host = '';

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new HreflangSingleKernel('test', true);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $slug = 'ci-hl-' . bin2hex(random_bytes(3));
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

    public function testSingleLanguageBrandLinksToItselfInHeadAndSitemap(): void
    {
        $base = 'https://' . $this->host;
        foreach (['/', '/blog/', '/blog/persha/', '/about/'] as $path) {
            $html = (string) $this->http('GET', $base . $path)->getContent();
            self::assertMatchesRegularExpression('~<link rel="canonical" href="([^"]+)">~', $html, "$path: canonical");
            preg_match('~<link rel="canonical" href="([^"]+)">~', $html, $c);
            self::assertStringContainsString('<link rel="alternate" hreflang="uk-UA" href="' . $c[1] . '">', $html, "$path: uk-UA = canonical");
            self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="' . $c[1] . '">', $html, "$path: x-default = canonical");
            self::assertSame(2, substr_count($html, 'hreflang="'), "$path: рівно два hreflang (uk-UA + x-default)");
        }
        $xml = (string) $this->http('GET', $base . '/sitemap.xml')->getContent();
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="uk-UA" href="' . $base . '/blog/persha/"/>', $xml);
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="x-default" href="' . $base . '/blog/persha/"/>', $xml);
        self::assertStringNotContainsString('hreflang="uk"', $xml, 'у sitemap — коди з app.locale_codes, як у <head>');

        $r = $this->http('GET', $base . '/nema-takoi-storinky/');
        self::assertSame(404, $r->getStatusCode());
        self::assertStringNotContainsString('hreflang="uk-UA"', (string) $r->getContent(), '404 — без hreflang');
    }
}
