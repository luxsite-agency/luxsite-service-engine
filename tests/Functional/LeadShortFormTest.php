<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;
use App\Tests\Support\LeadFieldsKernel;

/**
 * Обов'язкові поля заявки (app.lead_fields) і коротка форма: форма без полів (лише телефон) details[…] не шле — заявка
 * приймається без них; форма з полями, де обов'язкове порожнє, — назад з помилкою, заявки нема; повна — з details.
 */
final class LeadShortFormTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return LeadFieldsKernel::class;
    }

    public function testShortFormWithoutDetailsIsAccepted(): void
    {
        $token = null;
        foreach (['/', '/contacts/'] as $page) {
            $html = (string) $this->http('GET', 'https://' . $this->apex . $page)->getContent();
            if (preg_match('~<form[^>]*action="/lead"[^>]*>.*?name="_token" value="([^"]+)"~s', $html, $m)) {
                $token = html_entity_decode($m[1]);
                break;
            }
        }
        self::assertNotNull($token, 'на сайті є форма заявки з CSRF');

        $repo = fn () => $this->em()->getRepository(ServiceLead::class);
        $ip = fn () => ['REMOTE_ADDR' => '10.10.' . random_int(0, 255) . '.' . random_int(1, 254)];

        // форма з полями, обов'язкове порожнє — відмова
        $r = $this->http('POST', 'https://' . $this->apex . '/lead', ['_token' => $token, 'phone' => '+380670001151', 'details' => ['route' => '', 'weight' => '10']], $ip());
        self::assertSame(302, $r->getStatusCode());
        self::assertStringNotContainsString('thank-you', (string) $r->headers->get('Location'));
        $this->em()->clear();
        self::assertNull($repo()->findOneBy(['city' => $this->apexCityId, 'phone' => '+380670001151']));

        // коротка форма (лише телефон, без details) — приймається
        $r = $this->http('POST', 'https://' . $this->apex . '/lead', ['_token' => $token, 'phone' => '+380670001152', 'name' => 'Тест'], $ip());
        self::assertSame(302, $r->getStatusCode());
        self::assertStringContainsString('thank-you', (string) $r->headers->get('Location'));
        $this->em()->clear();
        $short = $repo()->findOneBy(['city' => $this->apexCityId, 'phone' => '+380670001152']);
        self::assertNotNull($short);
        self::assertNull($short->getDetails());

        // повна форма — з details
        $r = $this->http('POST', 'https://' . $this->apex . '/lead', ['_token' => $token, 'phone' => '+380670001153', 'details' => ['route' => 'Київ - Львів', 'weight' => '500']], $ip());
        self::assertStringContainsString('thank-you', (string) $r->headers->get('Location'));
        $this->em()->clear();
        self::assertSame('Київ - Львів', $repo()->findOneBy(['city' => $this->apexCityId, 'phone' => '+380670001153'])?->getDetails()['route'] ?? null);
    }
}
