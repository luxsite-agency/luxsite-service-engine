<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * Поле email заявки (POST /lead) на типовому ядрі: окрема колонка, лист-сповіщення, адмінка; невалідна адреса — помилка
 * форми без заявки; заявки лише з телефоном — як раніше. Довільні поля без app.lead.extra_fields не приймаються.
 */
class LeadEmailTest extends SeoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // адресат сповіщень: без нього LeadNotifier листа не шле
        $this->db()->executeStatement('UPDATE service_city SET lead_emails = ? WHERE id = ?', ['sales@example.test', $this->apexCityId]);
    }

    /** B1 */
    public function testEmailIsStoredAndMailed(): void
    {
        $r = $this->post(['email' => '  a@b.co ', 'name' => 'Ann']);
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'));
        $lead = $this->lastLead();
        self::assertSame('a@b.co', $lead->getEmail());

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailAddressContains($mail, 'To', 'sales@example.test');
        self::assertEmailTextBodyContains($mail, 'Email: a@b.co');
        self::assertEmailAddressContains($mail, 'Reply-To', 'a@b.co');

        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        foreach (['/admin/service-lead', '/admin/service-lead/' . $lead->getId()] as $path) {
            self::assertStringContainsString('a@b.co', (string) $this->http('GET', 'https://' . $this->adminHost . $path)->getContent(), $path);
        }
    }

    /** B2: невалідний email — помилка форми, заявки нема; без email — як раніше */
    public function testInvalidEmailIsRejectedAndPhoneOnlyLeadStillWorks(): void
    {
        $before = $this->leadCount();
        foreach (['не-пошта', 'a@b', "a@b.co\r\nBcc: x@y.z", str_repeat('a', 180) . '@b.co'] as $bad) {
            $r = $this->post(['email' => $bad]);
            self::assertSame('/contacts/', $r->headers->get('Location'), $bad);
            self::assertStringContainsString('Перевірте адресу email', (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent(), $bad);
        }
        self::assertSame($before, $this->leadCount(), 'заявку з невалідним email не створено');
        self::assertEmailCount(0);

        $r = $this->post(['email' => '']);
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'));
        $lead = $this->lastLead();
        self::assertNull($lead->getEmail());
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertEmailTextBodyNotContains($mail, 'Email:');
        self::assertFalse($mail->getHeaders()->has('Reply-To'));
    }

    public function testExtraFieldsAreIgnoredWithoutPackList(): void
    {
        $this->post(['address' => '390 Edge Of Woods']);
        self::assertNull($this->lastLead()->getExtra(), 'без app.lead.extra_fields довільні поля не зберігаються');
    }

    /** @param array<string, string> $fields */
    protected function post(array $fields): Response
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));

        return $this->http('POST', 'https://' . $this->apex . '/lead', [
            '_token' => html_entity_decode($m[1]),
            'phone' => '+380 67 ' . random_int(100, 999) . ' ' . random_int(10, 99) . ' 44',
        ] + $fields, ['REMOTE_ADDR' => '10.' . random_int(11, 250) . '.' . random_int(0, 255) . '.' . random_int(1, 254), 'HTTP_REFERER' => 'https://' . $this->apex . '/contacts/']);
    }

    protected function lastLead(): ServiceLead
    {
        $this->em()->clear();
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->apexCityId], ['id' => 'DESC']);
        self::assertInstanceOf(ServiceLead::class, $lead);

        return $lead;
    }

    private function leadCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM service_lead WHERE city_id = ?', [$this->apexCityId]);
    }
}
