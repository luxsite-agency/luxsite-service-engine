<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCity;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Правило редиректу бренду раніше за слеш-редирект (25.10.2026): /x без слеша з правилом — один 301 на ціль (не /x → /x/ →
 * ціль), правило, записане зі слешем, теж ловиться без слеша; 410 — одразу; правила нема — слеш, як було; лічильник
 * спрацювань росте. /old.php доходить до правил (nginx віддає *.php у фронт-контролер).
 */
final class RedirectRuleBeforeSlashTest extends HttpKernelTestCase
{
    private string $host;
    private int $siteId = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $slug = 'ci-redir-slash-' . bin2hex(random_bytes(3));
        $this->host = $slug . '.example';
        $site = (new ServiceSite())->setSlug($slug)->setName('CI Slash')->setApexDomain($this->host)->setMode(ServiceSite::MODE_CONTENT);
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->host)->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
        $em->persist((new ServicePost())->setSite($site)->setSlugUk('persha')->setTitleUk('Перша')->setDescUk('<p>Текст</p>')->setPublishedAt(new \DateTimeImmutable('-1 day')));
        $em->flush();
        $this->siteId = (int) $site->getId();
        $em->clear();
        $cityId = (int) $this->db()->fetchOne('SELECT id FROM service_city WHERE site_id = ?', [$this->siteId]);
        foreach ([['/stara-storinka', 301, '/blog/persha/', 0], ['/zi-slashem/', 301, '/blog/persha/', 0], ['/vydalena', 410, null, 0], ['/old-page.php', 301, '/blog/', 0],
            ['/vidoma-404', 404, null, 0], ['/misto', 301, '/blog/', 0], ['/misto/', 301, '/blog/persha/', $cityId], ['/blog/stara-kategoriia', 301, '/', 0]] as [$source, $code, $target, $cityKey]) {
            $this->db()->insert('service_redirect', ['site_id' => $this->siteId, 'city_key' => $cityKey, 'source' => $source, 'target' => $target,
                'target_path' => $target, 'code' => $code, 'kind' => 'manual', 'hits' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        }
    }

    protected function tearDown(): void
    {
        $this->db()->executeStatement('DELETE FROM service_redirect WHERE site_id = ?', [$this->siteId]);
        $this->db()->executeStatement('DELETE FROM service_site WHERE id = ?', [$this->siteId]);
        parent::tearDown();
    }

    public function testRuleWinsOverTrailingSlash(): void
    {
        $base = 'https://' . $this->host;
        foreach (['/stara-storinka' => '/blog/persha/', '/zi-slashem' => '/blog/persha/', '/stara-storinka?utm_source=x' => '/blog/persha/?utm_source=x'] as $from => $to) {
            $r = $this->http('GET', $base . $from);
            self::assertSame(301, $r->getStatusCode(), $from);
            self::assertSame($base . $to, $r->headers->get('Location'), $from . ' — один 301 на ціль, без проміжного слеша');
        }
        self::assertSame(410, $this->http('GET', $base . '/vydalena')->getStatusCode(), '410 — одразу, без слеш-редиректу');

        $old = $this->http('GET', $base . '/old-page.php');
        self::assertSame(301, $old->getStatusCode(), 'правило для .php спрацьовує');
        self::assertSame($base . '/blog/', $old->headers->get('Location'));

        $plain = $this->http('GET', $base . '/blog');
        self::assertSame(301, $plain->getStatusCode(), 'без правила — слеш, як було');
        self::assertSame($base . '/blog/', $plain->headers->get('Location'));

        $hits = (int) $this->db()->fetchOne('SELECT hits FROM service_redirect WHERE site_id = ? AND source = ?', [$this->siteId, '/stara-storinka']);
        self::assertSame(2, $hits, 'лічильник спрацювань (два запити до /stara-storinka)');

        // правило 404: одразу 404 без слеш-301, лічильник — один раз (NotFoundRedirectSubscriber)
        self::assertSame(404, $this->http('GET', $base . '/vidoma-404')->getStatusCode());
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT hits FROM service_redirect WHERE site_id = ? AND source = ?', [$this->siteId, '/vidoma-404']));
        // правило міста зі слешем перемагає правило сайту без слеша — як і було на 404
        self::assertSame($base . '/blog/persha/', $this->http('GET', $base . '/misto')->headers->get('Location'));
        // сміття старої CMS, зайве для адреси-джерела (strip_query_paths: /blog…), не їде й на ціль поза правилом (/), трекінг — їде
        self::assertSame($base . '/?utm_source=x', $this->http('GET', $base . '/blog/stara-kategoriia?Itemid=5&option=com_content&utm_source=x')->headers->get('Location'));
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
