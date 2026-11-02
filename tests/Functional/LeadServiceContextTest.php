<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;
use App\Entity\ServiceLeadFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Поля service і context заявки на типовому ядрі (app.lead_files вимкнено): окремі колонки, у dataLayer сторінки подяки;
 * без них подія як була. Файли без app.lead_files ігноруються, форма без multipart і поля файлів.
 */
final class LeadServiceContextTest extends SeoTestCase
{
    public function testServiceAndContextStoredAndInDataLayer(): void
    {
        $html = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertStringNotContainsString('multipart/form-data', $html, 'без app.lead_files — форма як була');
        self::assertStringNotContainsString('name="files[]"', $html);

        $path = (string) tempnam(sys_get_temp_dir(), 'ci-lead-');
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $r = $this->post($html, ['service' => '  <b>Нотаріальний</b>  переклад ', 'context' => 'шапка'], ['files' => [new UploadedFile($path, 'a.pdf', null, null, true)]]);
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'));
        $lead = $this->lastLead();
        self::assertSame('Нотаріальний переклад', $lead->getService(), 'без тегів і зайвих пробілів');
        self::assertSame('шапка', $lead->getContext());
        self::assertCount(0, $this->em()->getRepository(ServiceLeadFile::class)->findBy(['lead' => $lead]), 'файли без app.lead_files не приймаються');
        @unlink($path);

        $thanks = (string) $this->http('GET', 'https://' . $this->apex . '/thank-you/?src=form')->getContent();
        self::assertStringContainsString('lead_service: ' . json_encode('Нотаріальний переклад'), $thanks);
        self::assertStringContainsString('lead_context: ' . json_encode('шапка'), $thanks);
        $again = (string) $this->http('GET', 'https://' . $this->apex . '/thank-you/?src=form')->getContent();
        self::assertStringNotContainsString('lead_service', $again, 'флеш одноразовий');

        $this->post((string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent(), []);
        $lead = $this->lastLead();
        self::assertNull($lead->getService());
        self::assertNull($lead->getContext());
        self::assertStringNotContainsString('lead_service', (string) $this->http('GET', 'https://' . $this->apex . '/thank-you/?src=form')->getContent());
    }

    /**
     * @param array<string, string> $extra
     * @param array<string, mixed>  $files
     */
    private function post(string $page, array $extra, array $files = []): \Symfony\Component\HttpFoundation\Response
    {
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));

        return $this->http('POST', 'https://' . $this->apex . '/lead', [
            '_token' => html_entity_decode($m[1]),
            'phone' => '+380 67 ' . random_int(100, 999) . ' 33 44',
        ] + $extra, ['REMOTE_ADDR' => '10.7.' . random_int(0, 255) . '.' . random_int(1, 254), 'HTTP_REFERER' => 'https://' . $this->apex . '/contacts/'], $files);
    }

    private function lastLead(): ServiceLead
    {
        $this->em()->clear();
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->apexCityId], ['id' => 'DESC']);
        self::assertInstanceOf(ServiceLead::class, $lead);

        return $lead;
    }
}
