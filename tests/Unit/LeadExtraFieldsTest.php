<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\LeadExtraFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LeadExtraFieldsTest extends TestCase
{
    public function testCollectKeepsOnlyListedKeysAsCleanLines(): void
    {
        $fields = new LeadExtraFields(['address', ['key' => 'topic', 'label' => 'Тема']]);
        self::assertSame(['address', 'topic'], $fields->keys());
        self::assertSame(
            ['address' => '390 Edge Of Woods', 'topic' => 'Showing'],
            $fields->collect(['address' => " 390 <i>Edge</i>\tOf\r\nWoods", 'topic' => 'Showing', 'foo' => 'bar', 'phone' => '1']),
        );
        self::assertSame([], $fields->collect(['address' => ['a'], 'topic' => '   ']));
        self::assertSame(['address: A', 'Тема: T'], $fields->lines(['topic' => 'T', 'address' => 'A']), 'порядок паку, підпис або ключ');
        self::assertSame(['address: A', 'Тема: T', 'gone: x'], $fields->lines(['topic' => 'T', 'address' => 'A', 'gone' => 'x']), 'поле, прибране з паку, у старій заявці видно за ключем');
        self::assertSame([], (new LeadExtraFields())->collect(['address' => 'x']));
    }

    /** @return iterable<string, array{mixed}> */
    public static function badConfigs(): iterable
    {
        yield 'зайняте ім’я поля форми' => [['phone']];
        yield 'email — окреме поле' => [['email']];
        yield 'великі літери' => [['Address']];
        yield 'без ключа' => [[['label' => 'x']]];
        yield 'utm' => [['utm_source']];
    }

    #[DataProvider('badConfigs')]
    public function testBadKeyFailsAtBoot(mixed $config): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LeadExtraFields($config);
    }
}
