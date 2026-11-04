<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\Partners\PartnersConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PartnersConfigTest extends TestCase
{
    public function testDefaultsAndPartialPack(): void
    {
        $defaults = new PartnersConfig();
        self::assertFalse($defaults->enabled);
        self::assertSame('Europe/Kyiv', $defaults->timezone->getName());
        $pack = new PartnersConfig(['enabled' => true], 'America/New_York');
        self::assertTrue($pack->enabled);
        self::assertSame(3, $pack->shareMax);
        self::assertTrue($pack->unroutedNotify);
        self::assertSame('zip_from', $pack->zipFromKey);
        self::assertSame('zip_to', $pack->zipToKey);
    }

    public static function invalidConfigs(): iterable
    {
        yield [['share_max' => 0]];
        yield [['share_max' => 11]];
        yield [['share_max' => '3']];
        yield [['zip_from_key' => []]];
        yield [['zip_to_key' => 123]];
        yield [['zip_from_key' => '']];
        yield [['enabled' => 'false']];
        yield [['unrouted_notify' => 1]];
    }

    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidConfig(array $config): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PartnersConfig($config);
    }

    public function testRejectsInvalidTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PartnersConfig([], 'No/SuchTimezone');
    }
}
