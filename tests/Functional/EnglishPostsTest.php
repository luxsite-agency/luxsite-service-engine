<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use App\Repository\ServicePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Англомовні статті (22.10.2026): кілька статей без uk-слага на одному сайті (slug_uk NULL), предикат «існує мовою»
 * у списках і пошуку за слагом, uk/ru — як раніше.
 */
final class EnglishPostsTest extends KernelTestCase
{
    private int $siteId;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $slug = 'ci-en-' . bin2hex(random_bytes(3));
        $site = (new ServiceSite())->setSlug($slug)->setName('CI English')->setApexDomain($slug . '.example')->setLocales(['en']);
        $em->persist($site);
        $past = new \DateTimeImmutable('-1 day');
        $em->persist((new ServicePost())->setSite($site)->setSlugEn('first')->setTitleEn('First')->setPublishedAt($past));
        $em->persist((new ServicePost())->setSite($site)->setSlugEn('second')->setTitleEn('Second')->setPublishedAt($past));
        // слаг без заголовка — статті англійською ще нема
        $em->persist((new ServicePost())->setSite($site)->setSlugEn('draft')->setPublishedAt($past)->setSlugUk('chernetka')->setTitleUk('Чернетка'));
        $em->flush();
        $this->siteId = (int) $site->getId();
        $em->clear();
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $site = $em->find(ServiceSite::class, $this->siteId);
        if ($site !== null) {
            $em->remove($site); // статті — ON DELETE CASCADE
            $em->flush();
        }
        parent::tearDown();
    }

    public function testEnglishOnlyPostsCoexistAndFollowLocalePredicate(): void
    {
        $em = $this->em();
        $site = $em->find(ServiceSite::class, $this->siteId) ?? self::fail('сайт є');
        /** @var ServicePostRepository $repo */
        $repo = $em->getRepository(ServicePost::class);

        $first = $repo->findOneBy(['site' => $site, 'slugEn' => 'first']) ?? self::fail('стаття є');
        self::assertSame('', $first->getSlugUk(), 'slug_uk NULL віддається як порожній рядок');
        self::assertTrue($first->existsIn('en'));
        self::assertFalse($first->existsIn('uk'));

        self::assertSame(2, $repo->countPublishedBySite($site, 'en'), 'en — лише статті зі слагом і заголовком');
        self::assertSame(1, $repo->countPublishedBySite($site, 'uk'));
        self::assertSame(3, $repo->countPublishedBySite($site), 'без мови — як до 22.10.2026');
        $slugs = array_map(static fn (ServicePost $p): ?string => $p->getSlugEn(), $repo->findPublishedBySitePage($site, 1, 10, 'en'));
        sort($slugs);
        self::assertSame(['first', 'second'], $slugs);

        self::assertNotNull($repo->findOnePublishedBySlug($site, 'first', 'en'));
        self::assertNull($repo->findOnePublishedBySlug($site, 'draft', 'en'), 'без titleEn — 404');
        self::assertNull($repo->findOnePublishedBySlug($site, 'first', 'uk'));
        self::assertNotNull($repo->findOnePublishedBySlug($site, 'chernetka', 'ru'), 'ru падає на uk-слаг, як і раніше');
    }

    public function testEnglishFieldsHaveNoUkrainianFallback(): void
    {
        $post = (new ServicePost())->setSlugUk('x')->setTitleUk('Заголовок')->setLeadUk('Лід')->setImageAlt('Alt uk');
        self::assertNull($post->loc('title', 'en'));
        self::assertNull($post->loc('lead', 'en'));
        self::assertNull($post->imageAlt('en'));
        self::assertSame('Alt uk', $post->imageAlt('ru'), 'ru — фолбек на uk, як і раніше');
        self::assertSame('Заголовок', $post->loc('title', 'ru'));
        $post->setSlugUk('');
        self::assertSame('', $post->getSlugUk());
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }
}
