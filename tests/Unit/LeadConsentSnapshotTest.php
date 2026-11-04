<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\LeadConsent;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LeadConsentSnapshotTest extends TestCase
{
    public function testSnapshotUsesFormTextAndPolicyInPlace(): void
    {
        $consent = new LeadConsent(['enabled' => true, 'text' => ['uk' => 'Згода згідно з {policy}.'], 'policy_text' => ['uk' => 'політикою']]);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');
        self::assertSame('Згода згідно з політикою.', $consent->textForRecord('uk', $translator));
        self::assertNull((new LeadConsent())->textForRecord('uk', $translator));
    }

    public function testSnapshotUsesSameTranslationFallbackAsTemplate(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnCallback(static function (string $id, array $parameters, string $domain, string $locale): string {
            self::assertSame('servicesite', $domain);
            self::assertSame('ru', $locale);

            return $id === 'lead.consent' ? 'Согласие согласно {policy}' : 'политике';
        });
        self::assertSame('Согласие согласно политике', (new LeadConsent(['enabled' => true]))->textForRecord('ru', $translator));
    }

    public function testPolicyIsAppendedWhenTextHasNoPlaceholderLikeTemplate(): void
    {
        $consent = new LeadConsent(['enabled' => true, 'text' => 'Згода.', 'policy_text' => 'Політика']);
        self::assertSame('Згода. Політика', $consent->textForRecord('uk', $this->createStub(TranslatorInterface::class)));
    }
}
