<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Form\ExtraFieldsType;
use App\ServiceSite\CustomFields;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

// TypeTestCase сам створює мок диспетчера без очікувань
#[AllowMockObjectsWithoutExpectations]
final class ExtraFieldsTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        $fields = new CustomFields(['item' => [
            ['key' => 'tonnage', 'label' => ['uk' => 'Тоннаж'], 'localized' => true],
            ['key' => 'dimensions', 'label' => ['uk' => 'Габарити']],
        ]]);

        return [new PreloadedExtension([new ExtraFieldsType($fields)], [])];
    }

    public function testFieldsFollowPackDefinitionAndRoundTrip(): void
    {
        $form = $this->factory->create(ExtraFieldsType::class, ['tonnage' => ['uk' => 'від 23', 'ru' => 'от 23'], 'dimensions' => ['uk' => '11 х 2']], ['record_type' => 'item']);
        self::assertSame(['tonnage__uk', 'tonnage__ru', 'dimensions__uk'], array_keys($form->all()));
        self::assertSame('от 23', $form->get('tonnage__ru')->getData());

        $form->submit(['tonnage__uk' => '24', 'tonnage__ru' => '', 'dimensions__uk' => ' 12 х 3 ']);
        self::assertTrue($form->isSynchronized());
        self::assertSame(['tonnage' => ['uk' => '24'], 'dimensions' => ['uk' => '12 х 3']], $form->getData());
    }

    public function testNonLocalizedFieldDropsStaleRuValue(): void
    {
        // імпорт пише uk і ru; у формі нелокалізоване поле має лише __uk — після збереження ru не має лишатися старим
        $form = $this->factory->create(ExtraFieldsType::class, ['dimensions' => ['uk' => '11 х 2', 'ru' => '11 х 2 (старе)'], 'tonnage' => ['uk' => '20', 'ru' => '20 т']], ['record_type' => 'item']);
        $form->submit(['tonnage__uk' => '20', 'tonnage__ru' => '20 т', 'dimensions__uk' => '12 х 3']);
        self::assertTrue($form->isSynchronized());
        $data = $form->getData();
        ksort($data);
        self::assertSame(['dimensions' => ['uk' => '12 х 3'], 'tonnage' => ['uk' => '20', 'ru' => '20 т']], $data);
    }

    public function testNoDefinitionsGivesNull(): void
    {
        $form = $this->factory->create(ExtraFieldsType::class, null, ['record_type' => 'post']);
        self::assertSame([], $form->all());
        $form->submit([]);
        self::assertNull($form->getData());
    }
}
