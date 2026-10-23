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
use Symfony\Component\Filesystem\Filesystem;

/**
 * Дизайн на бренд (22.10.2026): два бренди одного розгортання з різною розміткою головної (templates/site/brands/<slug>),
 * блок page — уся видима частина, голова (canonical, аналітика) лишається рушія; бренд без своїх шаблонів — шаблон рушія.
 */
final class BrandTemplatesTest extends HttpKernelTestCase
{
    /** @var list<string> */
    private array $slugs = [];
    /** @var list<int> */
    private array $siteIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $fs = new Filesystem();
        $root = (string) self::getContainer()->getParameter('kernel.project_dir') . '/templates/site/brands/';
        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        foreach (['alpha', 'beta', 'plain'] as $name) {
            $slug = 'ci-' . $name . '-' . $suffix;
            $this->slugs[] = $slug;
            $site = (new ServiceSite())->setSlug($slug)->setName('CI ' . $name)->setApexDomain($slug . '.example')->setMode(ServiceSite::MODE_CONTENT);
            $em->persist($site);
            $em->persist((new ServiceCity())->setSite($site)->setHost($slug . '.example')->setSlug('main')->setIsApex(true)->setNameNomUk('Київ'));
            $em->flush();
            $this->siteIds[] = (int) $site->getId();
            // рубрики: у alpha — активна й вимкнена, у beta — своя (sv_categories бере лише активні рубрики поточного бренду)
            if ($name !== 'plain') {
                $on = (new ServicePostCategory())->setSite($site)->setSlugUk($name . '-on')->setTitleUk('Рубрика ' . $name)->setPosition(10);
                $em->persist($on);
                $off = (new ServicePostCategory())->setSite($site)->setSlugUk($name . '-off')->setTitleUk('Вимкнена ' . $name)->setActive(false);
                $em->persist($off);
                if ($name === 'alpha') {
                    // блоки рубрик: «Друга» (2 статті) більша за «Рубрика alpha» (1), стаття вимкненої рубрики не рахується
                    $second = (new ServicePostCategory())->setSite($site)->setSlugUk('alpha-second')->setTitleUk('Друга')->setPosition(20);
                    $em->persist($second);
                    foreach ([['s1', $second, '-3 days'], ['s2', $second, '-1 day'], ['o1', $on, '-2 days'], ['x1', $off, '-1 hour']] as [$ps, $cat, $when]) {
                        $em->persist((new ServicePost())->setSite($site)->setSlugUk('ci-' . $ps)->setTitleUk('Стаття ' . $ps)->setCategory($cat)->setPublishedAt(new \DateTimeImmutable($when)));
                    }
                }
                $em->flush();
                $fs->dumpFile($root . $slug . '/services/content/contact.html.twig',
                    "{% extends '@engine/services/base.html.twig' %}"
                    . "{% block page %}<nav class=\"sections\">{% for c in sv_categories() %}[{{ c.loc('title', sv_locale()) }}]{% endfor %}</nav>"
                    . "<nav class=\"filled\">{% for c in sv_categories(true) %}[{{ c.loc('title', sv_locale()) }}]{% endfor %}</nav>"
                    . "<div class=\"blocks\">{% for b in sv_category_posts(4, 1) %}({{ b.category.loc('title', sv_locale()) }}:{{ b.count }}:{% for p in b.posts %}{{ p.slugUk }}{% endfor %}){% endfor %}</div>{% endblock %}");
            }
            if ($name !== 'plain') {
                $fs->dumpFile($root . $slug . '/services/content/home.html.twig',
                    "{% extends '@engine/services/base.html.twig' %}{% block meta_generator %}{% endblock %}"
                    . "{% block page %}<div class=\"brand-$name\">$name home</div>{% endblock %}");
            }
        }
        $em->clear();
    }

    protected function tearDown(): void
    {
        $root = (string) self::getContainer()->getParameter('kernel.project_dir') . '/templates/site/brands/';
        foreach ($this->slugs as $slug) {
            (new Filesystem())->remove($root . $slug);
        }
        $conn = $this->em()->getConnection();
        foreach ($this->siteIds as $id) {
            $conn->executeStatement('DELETE FROM service_post WHERE site_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM service_post_category WHERE site_id = ?', [$id]);
            $conn->executeStatement('DELETE FROM service_site WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    public function testEachBrandRendersItsOwnMarkup(): void
    {
        [$alpha, $beta, $plain] = $this->slugs;
        $a = (string) $this->http('GET', 'https://' . $alpha . '.example/')->getContent();
        $b = (string) $this->http('GET', 'https://' . $beta . '.example/')->getContent();
        $p = (string) $this->http('GET', 'https://' . $plain . '.example/')->getContent();

        self::assertStringContainsString('<div class="brand-alpha">alpha home</div>', $a);
        self::assertStringContainsString('<div class="brand-beta">beta home</div>', $b);
        self::assertStringNotContainsString('brand-alpha', $b);
        self::assertStringNotContainsString('c-head', $a, 'шапки рушія нема — блок page перекрито');
        self::assertStringContainsString('<link rel="canonical"', $a, 'голова (SEO) лишається рушія');
        self::assertStringNotContainsString('name="generator"', $a, 'бренд прибрав generator');
        self::assertStringContainsString('class="c-head"', $p, 'бренд без шаблонів — шаблон рушія');
        self::assertStringContainsString('name="generator"', $p);
    }

    public function testBrandCategoriesOnAnyPage(): void
    {
        [$alpha, $beta, $plain] = $this->slugs;
        $a = (string) $this->http('GET', 'https://' . $alpha . '.example/contacts/')->getContent();
        $b = (string) $this->http('GET', 'https://' . $beta . '.example/contacts/')->getContent();

        self::assertStringContainsString('<nav class="sections">[Рубрика alpha][Друга]</nav>', $a, 'лише активні рубрики свого бренду, за позицією');
        self::assertStringContainsString('<nav class="sections">[Рубрика beta]</nav>', $b);
        self::assertStringContainsString('<nav class="filled">[Рубрика alpha][Друга]</nav>', $a, 'sv_categories(true): рубрики зі статтями, за позицією');
        self::assertStringContainsString('<nav class="filled"></nav>', $b, 'рубрика без статей у меню не показується');
        self::assertSame(200, $this->http('GET', 'https://' . $plain . '.example/contacts/')->getStatusCode());
    }

    public function testCategoryPostsBiggestFirst(): void
    {
        [$alpha, $beta] = $this->slugs;
        $a = (string) $this->http('GET', 'https://' . $alpha . '.example/contacts/')->getContent();
        $b = (string) $this->http('GET', 'https://' . $beta . '.example/contacts/')->getContent();

        self::assertStringContainsString('<div class="blocks">(Друга:2:ci-s2)(Рубрика alpha:1:ci-o1)</div>', $a, 'більша рубрика першою, свіжа стаття, вимкнена рубрика пропущена');
        self::assertStringContainsString('<div class="blocks"></div>', $b, 'рубрика без статей пропущена');
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
