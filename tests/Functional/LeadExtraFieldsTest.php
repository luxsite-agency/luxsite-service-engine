<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\LeadExtraKernel;
use Symfony\Component\Mime\Email;

/**
 * Довільні поля форми заявки (app.lead.extra_fields паку): дозволені ключі лягають у JSON-колонку заявки, видно в адмінці
 * й листі; незнайомі ключі відкидаються.
 */
final class LeadExtraFieldsTest extends LeadEmailTest
{
    protected static function getKernelClass(): string
    {
        return LeadExtraKernel::class;
    }

    /** B3 */
    public function testAllowedKeysAreStoredUnknownDropped(): void
    {
        $r = $this->post([
            'address' => "  390 <b>Edge</b> Of\n Woods ", 'foo' => 'bar', 'topic' => '', 'showing_day' => 'Saturday', 'email' => 'buyer@example.test',
        ]);
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'));
        $lead = $this->lastLead();
        self::assertSame(['address' => '390 Edge Of Woods', 'showing_day' => 'Saturday'], $lead->getExtra(), 'лише дозволені й заповнені; без тегів і переносів');
        self::assertEquals(
            ['address' => '390 Edge Of Woods', 'showing_day' => 'Saturday'],
            json_decode((string) $this->db()->fetchOne('SELECT extra FROM service_lead WHERE id = ?', [$lead->getId()]), true),
        );

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailTextBodyContains($mail, 'Адреса будинку: 390 Edge Of Woods');
        self::assertEmailTextBodyContains($mail, 'showing_day: Saturday');
        self::assertEmailTextBodyNotContains($mail, 'bar');

        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $detail = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-lead/' . $lead->getId())->getContent();
        self::assertStringContainsString('Адреса будинку: 390 Edge Of Woods', $detail);
        self::assertStringContainsString('showing_day: Saturday', $detail);
    }

    public function testExtraFieldsAreIgnoredWithoutPackList(): void
    {
        // це ядро список має; чужий ключ не зберігається
        $this->post(['foo' => 'bar']);
        self::assertNull($this->lastLead()->getExtra());
    }

    public function testArrayValueAndOverlongValueAreSafe(): void
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));
        $r = $this->http('POST', 'https://' . $this->apex . '/lead', [
            '_token' => html_entity_decode($m[1]), 'phone' => '+380 67 ' . random_int(100, 999) . ' 11 22',
            'address' => ['x' => 'y'], 'topic' => str_repeat('я', 700),
        ], ['REMOTE_ADDR' => '10.9.' . random_int(0, 255) . '.' . random_int(1, 254), 'HTTP_REFERER' => 'https://' . $this->apex . '/contacts/']);
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'));
        self::assertSame(['topic' => str_repeat('я', 500)], $this->lastLead()->getExtra(), 'масив відкинуто, рядок обрізано до 500');
    }
}
