<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceCalcType;
use App\Entity\ServiceLead;
use App\Entity\ServiceSite;
use App\Tests\Support\CalcAreaKernel;

/**
 * Калькулятор «за площею» з app.calculator.area { quote: false, quantity: false, lead_fields: true }: сторінка без сум,
 * без кроку площі й без платних опцій, у формі — додаткові поля заявки; заявка без площі, з полями в details і рядком
 * «вартість розрахує менеджер» замість суми; без обов'язкового поля заявки нема.
 */
final class CalculatorAreaOptionsTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return CalcAreaKernel::class;
    }

    public function testNoQuoteNoQuantityWithLeadFields(): void
    {
        $em = $this->em();
        $type = (new ServiceCalcType())->setSite($em->getReference(ServiceSite::class, $this->siteId))->setNameUk('Тент 20 т')->setPricePerSqm('0')->setMinPrice('0')->setActive(true);
        $em->persist($type);
        $em->flush();
        $typeId = (int) $type->getId();
        $em->clear();

        $r = $this->http('GET', 'https://' . $this->apex . '/calculator-cleaning/');
        self::assertSame(200, $r->getStatusCode());
        $html = (string) $r->getContent();
        self::assertStringContainsString('Тент 20 т', $html);
        self::assertStringNotContainsString('id="calc-total"', $html, 'quote: false — сум нема');
        self::assertStringNotContainsString('name="area"', $html, 'quantity: false — кроку площі нема');
        self::assertStringContainsString('name="details[route]"', $html, 'lead_fields: true — поля заявки у формі');
        self::assertStringContainsString('менеджер розрахує вартість', $html);
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $html, $m));
        $token = html_entity_decode($m[1]);

        $repo = fn () => $this->em()->getRepository(ServiceLead::class);
        $before = \count($repo()->findBy(['city' => $this->apexCityId]));

        // без обов'язкового поля — назад на калькулятор, заявки нема
        $r = $this->http('POST', 'https://' . $this->apex . '/calculator-cleaning/lead', [
            '_token' => $token, 'loc' => 'uk', 'phone' => '+380670001141', 'type' => (string) $typeId, 'details' => ['weight' => '1200'],
        ], ['REMOTE_ADDR' => '10.9.' . random_int(0, 255) . '.' . random_int(1, 254)]);
        self::assertSame(302, $r->getStatusCode());
        self::assertSame('/calculator-cleaning/', $r->headers->get('Location'));
        self::assertCount($before, $repo()->findBy(['city' => $this->apexCityId]));

        // без площі й без суми — заявка з полями
        $r = $this->http('POST', 'https://' . $this->apex . '/calculator-cleaning/lead', [
            '_token' => $token, 'loc' => 'uk', 'phone' => '+380670001142', 'type' => (string) $typeId,
            'details' => ['route' => 'Київ - Варшава', 'weight' => '1200'],
        ], ['REMOTE_ADDR' => '10.9.' . random_int(0, 255) . '.' . random_int(1, 254)]);
        self::assertSame(302, $r->getStatusCode());
        self::assertStringContainsString('src=calc', (string) $r->headers->get('Location'));

        $this->em()->clear();
        $lead = $repo()->findOneBy(['city' => $this->apexCityId, 'phone' => '+380670001142']);
        self::assertNotNull($lead);
        self::assertSame('Київ - Варшава', $lead->getDetails()['route'] ?? null);
        $message = (string) $lead->getMessage();
        self::assertStringContainsString('Тент 20 т', $message);
        self::assertStringContainsString('Вартість розрахує менеджер', $message);
        self::assertStringNotContainsString('Площа', $message);
        self::assertStringNotContainsString('Разом', $message);
        self::assertStringContainsString('Київ - Варшава', $message);
    }
}
