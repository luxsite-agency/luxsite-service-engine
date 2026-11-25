<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCity;
use PHPUnit\Framework\TestCase;

final class EntityEnParityTest extends TestCase
{
    public function testEveryUkrainianSetterHasAnEnglishVariant(): void
    {
        // Ukrainian grammatical cases are not needed in English.
        $allowlist = [ServiceCity::class => ['NameGen', 'NameDat', 'NameLoc']];

        foreach (['ServiceCity', 'ServiceCategory', 'ServiceItem', 'ServiceDistrict', 'ServicePage', 'ServicePost', 'ServicePrice', 'ServiceItemCity', 'ServiceCategoryCity'] as $name) {
            $class = 'App\\Entity\\' . $name;
            if (!class_exists($class)) {
                continue;
            }

            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!preg_match('/^set(\w+)Uk$/', $method->getName(), $match)) {
                    continue;
                }
                if (\in_array($match[1], $allowlist[$class] ?? [], true)) {
                    continue;
                }

                $setter = 'set' . $match[1] . 'En';
                self::assertTrue(method_exists($class, $setter), $class . '::' . $setter . ' is missing');
                self::assertTrue((new \ReflectionMethod($class, $setter))->isPublic(), $class . '::' . $setter . ' must be public');
            }
        }
    }

    public function testCityEnglishFieldsHaveNoUkrainianFallback(): void
    {
        $city = new ServiceCity();
        foreach (['districtsIntro', 'aboutWhere', 'priceNote', 'address'] as $field) {
            $setter = 'set' . ucfirst($field);
            $city->{$setter . 'Uk'}('Український текст');
            self::assertNull($city->loc($field, 'en'), $field);
            $city->{$setter . 'En'}('English text');
            self::assertSame('English text', $city->loc($field, 'en'), $field);
            $city->{$setter . 'En'}('');
            self::assertNull($city->loc($field, 'en'), $field);
        }
    }
}
