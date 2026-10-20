<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServiceTeamMember;
use App\ServiceSite\CustomFields;
use PHPUnit\Framework\TestCase;

/** Людина команди: основне місто + інші міста, слаг сторінки, додаткові поля (app.custom_fields.team). */
final class TeamMemberTest extends TestCase
{
    public function testWorksInPrimaryAndExtraCities(): void
    {
        $kyiv = (new ServiceCity())->setSlug('kyiv');
        $bila = (new ServiceCity())->setSlug('bila');
        $odesa = (new ServiceCity())->setSlug('odesa');
        $m = (new ServiceTeamMember())->setCity($kyiv)->addCity($bila)->addCity($kyiv)->addCity($bila);

        self::assertTrue($m->worksIn($kyiv));
        self::assertTrue($m->worksIn($bila));
        self::assertFalse($m->worksIn($odesa));
        self::assertSame([$kyiv, $bila], $m->allCities(), 'основне першим, без повторів');
        self::assertCount(2, $m->getCities(), 'addCity не дублює');

        $m->removeCity($bila);
        self::assertFalse($m->worksIn($bila));
    }

    public function testSlugPageAndRuFallback(): void
    {
        $m = new ServiceTeamMember();
        self::assertFalse($m->hasPage());
        self::assertNull($m->slug('uk'));

        $m->setSlugUk('  ')->setSlugRu('');
        self::assertNull($m->getSlugUk(), 'порожній слаг з адмінки = без сторінки');
        self::assertNull($m->getSlugRu());

        $m->setSlugUk('ivanenko-ivan');
        self::assertTrue($m->hasPage());
        self::assertSame('ivanenko-ivan', $m->slug('ru'), 'ru без свого слагу бере uk');
        $m->setSlugRu('ivanenko-ivan-ru');
        self::assertSame('ivanenko-ivan-ru', $m->slug('ru'));
        self::assertSame('ivanenko-ivan', $m->slug('uk'));
    }

    public function testItems(): void
    {
        $a = (new ServiceItem())->setSlugUk('a');
        $m = (new ServiceTeamMember())->addItem($a)->addItem($a);
        self::assertCount(1, $m->getItems());
        $m->removeItem($a);
        self::assertCount(0, $m->getItems());
    }

    public function testCustomFieldsForTeam(): void
    {
        $fields = new CustomFields([
            'team' => [
                ['key' => 'experience', 'label' => ['uk' => 'Стаж', 'ru' => 'Стаж']],
                ['key' => 'education', 'label' => ['uk' => 'Освіта', 'ru' => 'Образование'], 'localized' => true],
            ],
        ]);
        $m = (new ServiceTeamMember())->setExtra([
            'education' => ['uk' => 'НМУ ім. О. Богомольця', 'ru' => 'НМУ им. А. Богомольца'],
            'experience' => ['uk' => 'з 2004 року'],
        ]);

        self::assertSame('team', CustomFields::typeOf($m));
        self::assertTrue($fields->has('team'));
        $uk = $fields->display($m, 'uk');
        self::assertSame(['experience', 'education'], array_column($uk, 'key'), 'порядок — з паку');
        self::assertSame('з 2004 року', $uk[0]['value']);
        $ru = $fields->display($m, 'ru');
        self::assertSame('Образование', $ru[1]['label']);
        self::assertSame('НМУ им. А. Богомольца', $ru[1]['value']);
        self::assertSame('з 2004 року', $ru[0]['value'], 'нелокалізоване поле: ru = uk');
    }
}
