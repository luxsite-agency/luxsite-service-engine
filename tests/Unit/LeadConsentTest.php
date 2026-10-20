<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\LeadConsent;
use PHPUnit\Framework\TestCase;

final class LeadConsentTest extends TestCase
{
    public function testDisabledByDefaultFormsUnchanged(): void
    {
        $c = new LeadConsent(['enabled' => false, 'text' => [], 'policy_text' => [], 'policy_url' => []]);
        self::assertFalse($c->enabled());
        self::assertNull($c->forForm('uk'));
        self::assertTrue($c->accepted(null), 'без галочки заявка йде, як раніше');
        self::assertFalse((new LeadConsent())->enabled());
    }

    public function testEnabledRequiresCheckbox(): void
    {
        $c = new LeadConsent(['enabled' => true]);
        self::assertTrue($c->accepted('1'));
        self::assertTrue($c->accepted('on'));
        self::assertFalse($c->accepted(null));
        self::assertFalse($c->accepted(''));
        self::assertFalse($c->accepted('0'));
        self::assertSame(['text' => '', 'policy_text' => '', 'policy_url' => ''], $c->forForm('ru'), 'порожнє — дефолти рушія в шаблоні');
    }

    public function testTextsFromPackWithRuFallback(): void
    {
        $c = new LeadConsent([
            'enabled' => true,
            'text' => ['uk' => 'Згоден з {policy}', 'ru' => 'Согласен с {policy}'],
            'policy_text' => ['uk' => 'політикою'],
            'policy_url' => '/privacy-policy/',
        ]);
        self::assertSame(['text' => 'Згоден з {policy}', 'policy_text' => 'політикою', 'policy_url' => '/privacy-policy/'], $c->forForm('uk'));
        self::assertSame(['text' => 'Согласен с {policy}', 'policy_text' => 'політикою', 'policy_url' => '/privacy-policy/'], $c->forForm('ru'));
    }
}
