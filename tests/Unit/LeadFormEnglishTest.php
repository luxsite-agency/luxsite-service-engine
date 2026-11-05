<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\LeadConsent;
use App\ServiceSite\LeadFields;
use PHPUnit\Framework\TestCase;

/**
 * Англомовне розгортання (app.locales: [en]): підписи полів заявки, варіанти списку й текст згоди мовою сторінки.
 * Пак може задати лише en — тоді uk/ru беруть en (підсумок заявки для менеджера, перевірки «непорожній підпис»).
 */
final class LeadFormEnglishTest extends TestCase
{
    public function testFieldLabelsAndOptionsInEnglish(): void
    {
        $fields = new LeadFields([
            ['key' => 'zip_from', 'label' => ['en' => 'Moving from (ZIP)'], 'type' => 'text', 'required' => true],
            ['key' => 'home_size', 'label' => ['uk' => 'Житло', 'en' => 'Home size'], 'type' => 'select', 'source' => 'list',
                'options' => [['value' => 'studio', 'label' => ['en' => 'Studio']], ['value' => 'house', 'label' => ['uk' => 'Будинок', 'en' => 'House']]]],
        ]);
        $en = $fields->forForm('en');
        self::assertSame('Moving from (ZIP)', $en[0]['label']);
        self::assertSame('Home size', $en[1]['label']);
        self::assertSame(['Studio', 'House'], array_column($en[1]['options'], 'label'));
        $uk = $fields->forForm('uk');
        self::assertSame('Moving from (ZIP)', $uk[0]['label'], 'лише en у паку — uk бере en');
        self::assertSame('Житло', $uk[1]['label']);
        self::assertSame(['Studio', 'Будинок'], array_column($uk[1]['options'], 'label'));
        self::assertStringContainsString('Moving from (ZIP): 11968', $fields->summary(['zip_from' => '11968']));
    }

    public function testConsentInEnglishWithUkFallback(): void
    {
        $c = new LeadConsent([
            'enabled' => true,
            'text' => ['en' => 'I agree to be contacted. See {policy}.'],
            'policy_text' => ['en' => 'Privacy Policy'],
            'policy_url' => '/privacy/',
        ]);
        self::assertSame(['text' => 'I agree to be contacted. See {policy}.', 'policy_text' => 'Privacy Policy', 'policy_url' => '/privacy/'], $c->forForm('en'));
        self::assertSame('I agree to be contacted. See {policy}.', $c->forForm('uk')['text'], 'лише en у паку — uk бере en');
        $both = new LeadConsent(['enabled' => true, 'text' => ['uk' => 'Згоден', 'ru' => 'Согласен', 'en' => 'I agree']]);
        self::assertSame(['Згоден', 'Согласен', 'I agree'], [$both->forForm('uk')['text'], $both->forForm('ru')['text'], $both->forForm('en')['text']]);
    }
}
