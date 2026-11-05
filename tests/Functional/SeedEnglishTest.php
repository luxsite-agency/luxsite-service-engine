<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCategory;
use App\Entity\ServiceCity;
use App\Entity\ServiceDistrict;
use App\Entity\ServiceItem;
use App\Entity\ServicePage;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use App\Tests\Support\EnglishCatalogKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * app:site:seed для англомовного розгортання: поля `<key>_en` і `slug_en` категорій, послуг, районів і сторінок,
 * `body_en` — синонім `desc_en` сторінки.
 */
final class SeedEnglishTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return EnglishCatalogKernel::class;
    }

    public function testSeedFillsEnglishFieldsAndSlugs(): void
    {
        $s = bin2hex(random_bytes(4));
        $yaml = sys_get_temp_dir() . "/seed-en-$s.yaml";
        file_put_contents($yaml, <<<YAML
            site: {slug: seed-en-$s, name: 'Seed EN', apex: seed-en-$s.example}
            cities:
              - {slug: main, host: seed-en-$s.example, apex: true, name_nom_uk: 'Hamptons', name_nom_en: 'Hamptons', districts_intro_en: 'Our districts', about_where_en: 'Across the Hamptons', price_note_en: 'Minimum call-out USD 100', address_en: '123 Main Street'}
            categories:
              - {slug: pereizd-$s, slug_en: moving-$s, title_uk: 'Переїзд', title_en: 'Moving', meta_title_en: 'Moving services'}
            items:
              - {slug: mistsevyi-$s, slug_en: local-$s, category: pereizd-$s, title_uk: 'Місцевий', title_en: 'Local moving', lead_en: 'Across town'}
            districts:
              - {city: main, slug: sauthempton-$s, slug_en: southampton-$s, name_uk: 'Саутгемптон', name_en: 'Southampton', group_en: 'Southampton Town'}
            posts:
              - {slug: novyna-$s, slug_en: news-$s, title_en: 'Moving news'}
            pages:
              - {slug: yak-tse-pratsiuie-$s, slug_en: how-it-works-$s, title_uk: 'Як це працює', title_en: 'How it works', body_en: '<p>Three steps.</p>'}
            YAML);
        $app = new Application(static::$kernel);
        $app->setAutoExit(false);
        try {
            self::assertSame(0, $app->run(new ArrayInput(['command' => 'app:site:seed', 'file' => $yaml]), $out = new BufferedOutput()), $out->fetch());
        } finally {
            @unlink($yaml);
        }

        $em = $this->em();
        $em->clear();
        $site = $em->getRepository(ServiceSite::class)->findOneBy(['slug' => "seed-en-$s"]);
        self::assertNotNull($site);
        $city = $em->getRepository(ServiceCity::class)->findOneBy(['site' => $site, 'slug' => 'main']);
        self::assertNotNull($city);
        self::assertSame('Our districts', $city->getDistrictsIntroEn());
        self::assertSame('Across the Hamptons', $city->getAboutWhereEn());
        self::assertSame('Minimum call-out USD 100', $city->getPriceNoteEn());
        self::assertSame('123 Main Street', $city->getAddressEn());
        self::assertSame('Our districts', $city->loc('districtsIntro', 'en'));
        $cat = $em->getRepository(ServiceCategory::class)->findOneBy(['site' => $site, 'slugUk' => "pereizd-$s"]);
        self::assertSame(["moving-$s", 'Moving', 'Moving services'], [$cat?->getSlugEn(), $cat?->getTitleEn(), $cat?->getMetaTitleEn()]);
        $item = $em->getRepository(ServiceItem::class)->findOneBy(['site' => $site, 'slugUk' => "mistsevyi-$s"]);
        self::assertSame(["local-$s", 'Local moving', 'Across town'], [$item?->getSlugEn(), $item?->getTitleEn(), $item?->getLeadEn()]);
        $dist = $em->getRepository(ServiceDistrict::class)->findOneBy(['slugUk' => "sauthempton-$s"]);
        self::assertSame(["southampton-$s", 'Southampton', 'Southampton Town'], [$dist?->getSlugEn(), $dist?->getNameEn(), $dist?->getGroupEn()]);
        $post = $em->getRepository(ServicePost::class)->findOneBy(['site' => $site, 'slugUk' => "novyna-$s"]);
        self::assertSame(["news-$s", 'Moving news'], [$post?->getSlugEn(), $post?->getTitleEn()]);
        $page = $em->getRepository(ServicePage::class)->findOneBy(['site' => $site, 'slugUk' => "yak-tse-pratsiuie-$s"]);
        self::assertSame(["how-it-works-$s", 'How it works', '<p>Three steps.</p>'], [$page?->getSlugEn(), $page?->getTitleEn(), $page?->getDescEn()]);
    }
}
