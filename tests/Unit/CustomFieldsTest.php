<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCategory;
use App\Entity\ServiceItem;
use App\Entity\ServicePost;
use App\ServiceSite\CustomFields;
use PHPUnit\Framework\TestCase;

final class CustomFieldsTest extends TestCase
{
    private function fields(): CustomFields
    {
        return new CustomFields([
            'item' => [
                ['key' => 'tonnage', 'label' => ['uk' => 'Вантажопідйомність', 'ru' => 'Грузоподъёмность'], 'suffix' => ['uk' => ' т'], 'localized' => true, 'categories' => ['vehicle-type-transportation']],
                ['key' => 'dimensions', 'label' => ['uk' => 'Габарити'], 'suffix' => ['uk' => ' м']],
                ['key' => 'fleet_group', 'label' => ['uk' => 'Група'], 'filter' => true, 'categories' => ['vehicle-type-transportation']],
            ],
        ]);
    }

    private function item(string $categorySlug): ServiceItem
    {
        return (new ServiceItem())->setCategory((new ServiceCategory())->setSlugUk($categorySlug));
    }

    public function testDefaultsAreEmpty(): void
    {
        $f = new CustomFields([]);
        self::assertFalse($f->has('item'));
        self::assertSame([], $f->display($this->item('x')->setExtra(['tonnage' => ['uk' => '5']]), 'uk'));
    }

    public function testDisplayRespectsCategoryOrderSuffixAndLocale(): void
    {
        $item = $this->item('vehicle-type-transportation')->setExtra([
            'dimensions' => ['uk' => '12 х 2,5 х 3'],
            'tonnage' => ['uk' => 'від 23', 'ru' => 'от 23'],
            'fleet_group' => ['uk' => 'Цистерни'],
        ]);
        $uk = $this->fields()->display($item, 'uk');
        self::assertSame(['tonnage', 'dimensions', 'fleet_group'], array_column($uk, 'key'));
        self::assertSame('від 23 т', $uk[0]['value']);
        self::assertSame('12 х 2,5 х 3 м', $uk[1]['value']);
        self::assertTrue($uk[2]['filter']);

        $ru = $this->fields()->display($item, 'ru');
        self::assertSame('Грузоподъёмность', $ru[0]['label']);
        self::assertSame('от 23 т', $ru[0]['value'], 'ru suffix falls back to uk suffix');
        self::assertSame('Габарити', $ru[1]['label'], 'ru label falls back to uk');
        self::assertSame('12 х 2,5 х 3 м', $ru[1]['value'], 'empty ru value falls back to uk');
    }

    public function testCategoryScopeHidesFieldsElsewhere(): void
    {
        $item = $this->item('relocations')->setExtra(['tonnage' => ['uk' => '5'], 'dimensions' => ['uk' => '1 х 1']]);
        self::assertSame(['dimensions'], array_column($this->fields()->display($item, 'uk'), 'key'));
    }

    public function testEmptyValuesAreDroppedAndTypesResolve(): void
    {
        $item = $this->item('vehicle-type-transportation')->setExtra(['tonnage' => ['uk' => '  ', 'ru' => '']]);
        self::assertNull($item->getExtra());
        self::assertSame('post', CustomFields::typeOf(new ServicePost()));
        self::assertSame('category', CustomFields::typeOf(new ServiceCategory()));
        self::assertNull(CustomFields::typeOf(new \stdClass()));
    }

    public function testBadKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomFields(['item' => [['key' => 'Bad Key']]]);
    }
}
