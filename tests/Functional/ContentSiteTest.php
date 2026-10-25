<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceAuthor;
use App\Entity\ServiceCity;
use App\Entity\ServiceLead;
use App\Entity\ServicePost;
use App\Entity\ServicePostCategory;
use App\Entity\ServiceSite;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Контентний сайт (ServiceSite.mode = content, 22.10.2026): головна зі статтями, рубрика, автор, стаття, форма зв'язку,
 * sitemap; сервісні маршрути — 404, контактів міста на сторінках нема. Сервісний сайт не бачить рубрик і авторів.
 */
final class ContentSiteTest extends HttpKernelTestCase
{
    private string $host;
    private string $servicesHost;
    /** @var list<int> */
    private array $siteIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $slug = 'ci-content-' . bin2hex(random_bytes(3));
        $this->host = $slug . '.example';
        $this->servicesHost = 'svc-' . $this->host;

        $site = (new ServiceSite())->setSlug($slug)->setName('CI Journal')->setApexDomain($this->host)->setMode(ServiceSite::MODE_CONTENT)
            ->setAboutUk('<p>Про журнал.</p>');
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->host)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ')
            ->setPhone('+380 44 000 00 00')->setEmail('public@example.com')->setLeadEmails('inbox@example.com')->setIntroTitleUk('Журнал про ремонт'));
        $cat = (new ServicePostCategory())->setSite($site)->setSlugUk('poradi')->setTitleUk('Поради');
        $author = (new ServiceAuthor())->setSite($site)->setSlug('redakcia')->setName('Редакція CI')->setRoleUk('Редактор');
        $em->persist($cat);
        $em->persist($author);
        $em->persist((new ServicePost())->setSite($site)->setSlugUk('persha')->setTitleUk('Перша стаття')->setLeadUk('Лід')
            ->setDescUk('<p>Текст</p>')->setCategory($cat)->setAuthor($author)->setPublishedAt(new \DateTimeImmutable('-1 day')));

        $svc = (new ServiceSite())->setSlug('svc-' . $slug)->setName('CI Services')->setApexDomain($this->servicesHost);
        $em->persist($svc);
        $em->persist((new ServiceCity())->setSite($svc)->setHost($this->servicesHost)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        $em->flush();
        $this->siteIds = [(int) $site->getId(), (int) $svc->getId()];
        $em->clear();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();
        $conn = $em->getConnection();
        foreach ($this->siteIds as $id) {
            // заявки міст сайту (FK без CASCADE), далі сайт — міста, статті, рубрики, автори йдуть ON DELETE CASCADE
            $conn->executeStatement('DELETE l FROM service_lead l JOIN service_city c ON c.id = l.city_id WHERE c.site_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM service_site WHERE id = ?', [$id]);
        }
        $em->flush();
        parent::tearDown();
    }

    /**
     * Службові коментарі не потрапляють у HTML (25.10.2026): у вбудованих <style> і <script> контентного й сервісного сайту —
     * жодного CSS/JS-коментаря (коментарі шаблонів — Twig {# #}, CSS теми мінімізується в SiteTheme::inlineCss).
     */
    public function testNoInlineComments(): void
    {
        foreach (['https://' . $this->host . '/', 'https://' . $this->host . '/blog/persha/', 'https://' . $this->servicesHost . '/'] as $url) {
            $response = $this->http('GET', $url);
            self::assertSame(200, $response->getStatusCode(), $url);
            $html = (string) $response->getContent();
            preg_match_all('~<style[^>]*>(.*?)</style>~si', $html, $styles);
            preg_match_all('~<script(?![^>]*(?:application/ld\+json|src=))[^>]*>(.*?)</script>~si', $html, $scripts);
            self::assertNotEmpty($styles[1], $url);
            foreach ($styles[1] as $css) {
                self::assertStringNotContainsString('/*', $css, $url . ' — коментар у <style>');
            }
            foreach ($scripts[1] as $js) {
                self::assertStringNotContainsString('/*', $js, $url . ' — коментар у <script>');
                self::assertDoesNotMatchRegularExpression('~(^|[\s;{}])//(?!/)~m', $js, $url . ' — //-коментар у <script>');
            }
        }
    }

    public function testContentPagesAndGuards(): void
    {
        $base = 'https://' . $this->host;
        $home = $this->http('GET', $base . '/');
        self::assertSame(200, $home->getStatusCode());
        $html = (string) $home->getContent();
        self::assertStringContainsString('Журнал про ремонт', $html);
        self::assertStringContainsString('/blog/persha/', $html);
        self::assertStringContainsString('"@type":"WebSite"', $html);
        self::assertStringNotContainsString('id="lead-modal"', $html, 'модалки заявки нема');
        self::assertStringNotContainsString('floating-cta">', $html, 'плаваючої кнопки заявки нема');
        self::assertStringNotContainsString('000 00 00', $html, 'телефону міста на контентному сайті нема');
        self::assertStringNotContainsString('public@example.com', $html);
        self::assertStringNotContainsString('LocalBusiness', $html);

        foreach (['/services/', '/districts/', '/faq/', '/reviews/', '/connect/google'] as $path) {
            self::assertSame(404, $this->http('GET', $base . $path)->getStatusCode(), "$path — 404 у контентному режимі");
        }

        $cat = $this->http('GET', $base . '/blog/category/poradi/');
        self::assertSame(200, $cat->getStatusCode());
        self::assertStringContainsString('Перша стаття', (string) $cat->getContent());
        self::assertSame(404, $this->http('GET', $base . '/blog/category/nema/')->getStatusCode());

        $author = $this->http('GET', $base . '/author/redakcia/');
        self::assertSame(200, $author->getStatusCode());
        self::assertStringContainsString('"@type":"Person"', (string) $author->getContent());

        $post = (string) $this->http('GET', $base . '/blog/persha/')->getContent();
        self::assertStringContainsString('Редакція CI', $post);
        self::assertMatchesRegularExpression('/"author":\{"@type":"Person","name":"Редакція CI"/u', $post);
        self::assertStringContainsString('"articleSection":"Поради"', $post);

        $about = $this->http('GET', $base . '/about/');
        self::assertSame(200, $about->getStatusCode());
        self::assertStringContainsString('Про журнал', (string) $about->getContent());

        $sitemap = (string) $this->http('GET', $base . '/sitemap.xml')->getContent();
        foreach (['/blog/persha/', '/blog/category/poradi/', '/author/redakcia/', '/about/', '/contacts/'] as $path) {
            self::assertStringContainsString($base . $path . '<', $sitemap, "у sitemap є $path");
        }
        self::assertStringNotContainsString('/services/', $sitemap);
        self::assertStringNotContainsString('/faq/', $sitemap);

        $svc = 'https://' . $this->servicesHost;
        self::assertSame(404, $this->http('GET', $svc . '/author/redakcia/')->getStatusCode(), 'сервісний сайт: авторів нема');
        self::assertSame(404, $this->http('GET', $svc . '/blog/category/poradi/')->getStatusCode());
    }

    public function testContactFormStoresEmailLead(): void
    {
        $base = 'https://' . $this->host;
        $page = (string) $this->http('GET', $base . '/contacts/')->getContent();
        self::assertStringNotContainsString('000 00 00', $page);
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m), 'форма з CSRF');

        // свій IP на прогін: ліміт заявок (LeadRateLimit) зберігається між прогонами в кеші var/
        $ip = sprintf('198.51.100.%d', random_int(1, 254));
        $bad = $this->http('POST', $base . '/lead', ['_token' => html_entity_decode($m[1]), 'email' => 'not-an-email', 'message' => 'Привіт'], ['HTTP_REFERER' => $base . '/contacts/', 'REMOTE_ADDR' => $ip]);
        self::assertTrue($bad->isRedirect($base . '/contacts/') || $bad->isRedirect('/contacts/'), 'невалідний email → назад на форму');

        $ok = $this->http('POST', $base . '/lead', ['_token' => html_entity_decode($m[1]), 'name' => 'Ім’я', 'email' => 'reader@example.com', 'message' => 'Питання про статтю'], ['HTTP_REFERER' => $base . '/contacts/', 'REMOTE_ADDR' => $ip]);
        self::assertTrue($ok->isRedirect(), 'успіх → «Дякуємо»');
        self::assertStringContainsString('/thank-you/', (string) $ok->headers->get('Location'));

        $em = $this->em();
        $em->clear();
        $city = $em->getRepository(ServiceCity::class)->findOneBy(['host' => $this->host]);
        $lead = $em->getRepository(ServiceLead::class)->findOneBy(['city' => $city]);
        self::assertNotNull($lead);
        self::assertSame('reader@example.com', $lead->getEmail());
        self::assertSame('', $lead->getPhone());

        self::assertSame(200, $this->http('GET', $base . '/thank-you/?src=contact')->getStatusCode());
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
