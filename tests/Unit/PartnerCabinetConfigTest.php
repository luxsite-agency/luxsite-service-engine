<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\Partners\PartnersConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PartnerCabinetConfigTest extends TestCase
{
    public function testDefaultsAndOverrides(): void
    {
        $config = new PartnersConfig();
        self::assertSame('en', $config->cabinetLocale);
        self::assertSame(['junk'], $config->billingExcludes);
        $config = new PartnersConfig(['cabinet_locale' => 'uk', 'billing_excludes' => ['junk', 'lost', 'junk']]);
        self::assertSame('uk', $config->cabinetLocale);
        self::assertSame(['junk', 'lost'], $config->billingExcludes);
        self::assertSame([], (new PartnersConfig(['billing_excludes' => []]))->billingExcludes);
    }

    public static function invalid(): iterable
    {
        foreach (['', 'fr', 'en_US', null, [], 1] as $locale) {
            yield [['cabinet_locale' => $locale]];
        }
        foreach (['junk', null, false, ['no_such_status'], [1], ['status' => 'junk']] as $excludes) {
            yield [['billing_excludes' => $excludes]];
        }
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidCabinetConfig(array $config): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PartnersConfig($config);
    }
}
