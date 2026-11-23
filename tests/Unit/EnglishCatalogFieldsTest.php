<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AdminApi\ContentService;
use App\Entity\ServiceCategory;
use App\Entity\ServiceCategoryCity;
use App\Entity\ServiceDistrict;
use App\Entity\ServiceItem;
use App\Entity\ServiceItemCity;
use App\Entity\ServicePage;
use App\Entity\ServicePrice;
use App\Entity\ServicePost;
use App\Entity\ServiceReview;
use App\Entity\ServiceTeamMember;
use App\Entity\ServiceWork;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\CustomFields;
use App\Twig\ServiceSiteExtension;
use PHPUnit\Framework\TestCase;

final class EnglishCatalogFieldsTest extends TestCase
{
    public function testEveryContentBlockKeepsEnglishSeparateFromUkrainian(): void
    {
        foreach ([
            [(new ServiceItem())->setTitleUk('Послуга'), 'title', 'Movers'],
            [(new ServiceCategory())->setTitleUk('Категорія'), 'title', 'Moving'],
            [(new ServiceDistrict())->setNameUk('Район'), 'name', 'Brooklyn'],
            [(new ServicePage())->setTitleUk('Сторінка'), 'title', 'Careers'],
            [(new ServiceItemCity())->setLeadUk('Вступ'), 'lead', 'Move in New York'],
            [(new ServiceCategoryCity())->setDescUk('Опис'), 'desc', '<p>Moving services</p>'],
            [(new ServicePrice())->setPriceTextUk('Договірна'), 'priceText', 'Contact us'],
            [(new ServiceTeamMember())->setRoleUk('Водій'), 'role', 'Driver'],
            [(new ServiceReview())->setTextUk('Відгук'), 'text', 'Great move!'],
            [(new ServiceWork())->setCaptionUk('Робота'), 'caption', 'Apartment move'],
        ] as [$entity, $field, $english]) {
            self::assertNull($entity->loc($field, 'en'), $entity::class);
            self::assertSame($entity->loc($field, 'uk'), $entity->loc($field, 'ru'));
            $entity->{'set' . ucfirst($field) . 'En'}($english);
            self::assertSame($english, $entity->loc($field, 'en'));
            $entity->{'set' . ucfirst($field) . 'En'}('');
            self::assertSame('', $entity->loc($field, 'en'));
        }
    }

    public function testPathsUseEnglishOrUkrainianAddressWithoutTextFallback(): void
    {
        $category = (new ServiceCategory())->setSlugUk('pereizdy')->setSlugEn('moving');
        $item = (new ServiceItem())->setCategory($category)->setSlugUk('vantazhivka')->setSlugEn('truck');
        self::assertSame(['slug' => 'truck', 'category' => 'moving'], (new CatalogUrls('nested'))->itemParams($item, 'en'));
        foreach ([$item, $category, new ServiceDistrict(), new ServicePage()] as $entity) {
            $entity->setSlugUk('original')->setSlugEn('english');
            self::assertSame('english', $entity->slug('en'));
            $entity->setSlugEn('');
            self::assertNull($entity->getSlugEn(), 'empty slug stored as NULL for repository fallback');
            self::assertSame('original', $entity->slug('en'));
        }
        $parent = (new ServicePage())->setSlugUk('vakansii')->setSlugEn('careers');
        $page = (new ServicePage())->setParent($parent)->setSlugUk('vodiy')->setTitleUk('Водій')->setH1Uk('Робота водієм');
        self::assertSame('careers/vodiy', $page->path('en'));
        self::assertSame('', $page->h1('en'));
        $page->setSlugEn('driver')->setTitleEn('Driver');
        self::assertSame('careers/driver', $page->path('en'));
        self::assertSame('Driver', $page->h1('en'));
        $page->setH1En('Join as a driver');
        self::assertSame('Join as a driver', $page->h1('en'));

        $post = (new ServicePost())->setSlugUk('news')->setTitleEn('News');
        self::assertNull($post->loc('slug', 'en'), 'posts keep their explicit English slug requirement');
        self::assertFalse($post->existsIn('en'));
    }

    public function testCityOverridesAndFaqNeverUseUkrainianInEnglish(): void
    {
        $twig = (new \ReflectionClass(ServiceSiteExtension::class))->newInstanceWithoutConstructor();
        $base = (new ServiceItem())->setTitleUk('Загальна назва')->setFaqUk([['q' => 'Питання', 'a' => 'Відповідь']]);
        $override = (new ServiceItemCity())->setTitleUk('Міська назва')->setFaqUk([['q' => 'Місто?', 'a' => 'Так']]);
        self::assertNull($twig->ov($override, $base, 'title', 'en'));
        self::assertNull($twig->ov($override, $base, 'faq', 'en'));
        self::assertSame('Міська назва', $twig->ov($override, $base, 'title', 'ru'));
        $base->setTitleEn('Movers')->setFaqEn([['q' => 'Packing?', 'a' => 'Yes']]);
        self::assertSame('Movers', $twig->ov($override, $base, 'title', 'en'));
        self::assertSame([['q' => 'Packing?', 'a' => 'Yes']], $twig->ov($override, $base, 'faq', 'en'));
        $override->setTitleEn('New York movers');
        self::assertSame('New York movers', $twig->ov($override, $base, 'title', 'en'));
        $override->setTitleEn('');
        self::assertSame('Movers', $twig->ov($override, $base, 'title', 'en'));
    }

    public function testEnglishCustomFieldsDoNotExposeUkrainianValuesOrLabels(): void
    {
        $fields = new CustomFields(['item' => [['key' => 'capacity', 'label' => ['uk' => 'Місткість', 'en' => 'Capacity'],
            'suffix' => ['uk' => ' тонн', 'en' => ' tons']]]]);
        $item = (new ServiceItem())->setExtra(['capacity' => ['uk' => '5']]);
        self::assertSame([], $fields->display($item, 'en'));
        $item->setExtra(['capacity' => ['uk' => '5', 'en' => '6']]);
        self::assertSame('Capacity', $fields->display($item, 'en')[0]['label']);
        self::assertSame('6 tons', $fields->display($item, 'en')[0]['value']);
    }

    public function testEnglishApiFieldsExposeContentAndSlugSetters(): void
    {
        foreach (['items' => ServiceItem::class, 'categories' => ServiceCategory::class, 'districts' => ServiceDistrict::class,
            'pages' => ServicePage::class, 'prices' => ServicePrice::class, 'item-texts' => ServiceItemCity::class,
            'category-texts' => ServiceCategoryCity::class, 'team' => ServiceTeamMember::class, 'reviews' => ServiceReview::class,
            'works' => ServiceWork::class] as $kind => $class) {
            foreach (ContentService::FIELDS[$kind] as $key => $property) {
                if (str_ends_with($key, '_uk')) {
                    self::assertArrayHasKey(substr($key, 0, -3) . '_en', ContentService::FIELDS[$kind]);
                }
            }
            if (method_exists($class, 'getSlugEn')) {
                self::assertSame('slugEn', ContentService::FIELDS[$kind]['slug_en']);
            }
        }
    }
}
