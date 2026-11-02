<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceApplication;
use App\Entity\ServiceLead;
use App\Settings\SiteSettingKeys;
use App\Settings\SiteSettings;
use App\Tests\Support\ApplicationsKernel;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Анкети (app.applications, ApplicationsKernel з формою translator): форма з полями паку; приймаються лише описані поля;
 * обов'язкові поля, згода й email перевіряються; файли — з лімітами форми (PDF, DOCX); анкета — не заявка (нема в заявках
 * і CRM), лист — на «Адресу для анкет» або email форми; /thank-you/ без конверсії; розділ «Анкети» в адмінці.
 */
final class ApplicationsTest extends SeoTestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected static function getKernelClass(): string
    {
        return ApplicationsKernel::class;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function testFormRendersFieldsFromPack(): void
    {
        $html = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertStringContainsString('action="/application/translator"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="work[]" value="oral"', $html);
        self::assertStringContainsString('<textarea name="about"', $html);
        self::assertStringContainsString('name="consent" value="1" required', $html);
        self::assertStringContainsString('name="context" value="контакти"', $html);
    }

    public function testApplicationStoredMailedAndNotALead(): void
    {
        $leadsBefore = \count($this->em()->getRepository(ServiceLead::class)->findAll());
        $r = $this->submit([
            'name' => 'Олена', 'phone' => '+380 50 123 45 67', 'email' => 'olena@example.test', 'city' => 'Львів',
            'languages' => 'англійська, польська', 'work' => ['written', 'editing', 'hack'], 'about' => "Досвід 5 років\n<b>ЗНО</b>",
            'consent' => '1', 'context' => 'контакти', 'unknown' => 'x',
        ], [$this->file("%PDF-1.4\n%%EOF\n", 'резюме.pdf'), $this->docx('dyplom.docx')]);
        self::assertSame(302, $r->getStatusCode(), (string) $r->getContent());
        self::assertSame('/thank-you/?src=app-translator', $r->headers->get('Location'));

        $a = $this->last();
        self::assertSame('translator', $a->getForm());
        self::assertSame('Олена', $a->getName());
        self::assertSame('olena@example.test', $a->getEmail());
        self::assertTrue($a->hasConsent());
        self::assertSame('Письмовий переклад, Редагування', $a->value('work'), 'лише варіанти з options');
        self::assertSame("Досвід 5 років\nЗНО", $a->value('about'));
        self::assertSame('контакти', $a->value('context'));
        self::assertNull($a->value('unknown'), 'неописані поля відкидаються');
        self::assertSame(['city', 'languages', 'work', 'about', 'context'], array_column($a->getData(), 'key'), 'порядок форми');
        self::assertCount(2, $a->getFiles());
        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $a->getFiles()[1]->getMimeType());
        self::assertSame($leadsBefore, \count($this->em()->getRepository(ServiceLead::class)->findAll()), 'не заявка клієнта');

        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'hr@example.test');
        self::assertEmailTextBodyContains($mail, 'Мови: англійська, польська');
        self::assertEmailTextBodyContains($mail, '/admin/lead-file/' . $a->getFiles()[0]->getId());

        $thanks = (string) $this->http('GET', 'https://' . $this->apex . '/thank-you/?src=app-translator')->getContent();
        self::assertStringContainsString("event: 'application_submit'", $thanks);
        self::assertStringNotContainsString('generate_lead', $thanks, 'анкета — не конверсія');

        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $detail = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-application/' . $a->getId())->getContent();
        self::assertStringContainsString('англійська, польська', $detail);
        self::assertStringContainsString('резюме.pdf', $detail);
        $menu = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin')->getContent();
        self::assertStringContainsString('Анкети', $menu);
    }

    public function testSiteSettingOverridesFormEmail(): void
    {
        $settings = static::getContainer()->get(SiteSettings::class);
        $settings->set($this->site(), SiteSettingKeys::APPLICATIONS_EMAIL, 'jobs@example.test, hr2@example.test');
        $this->em()->flush();
        $this->submit($this->valid(), []);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAddressContains($mail, 'To', 'jobs@example.test');
        self::assertEmailAddressContains($mail, 'To', 'hr2@example.test');
    }

    public function testValidation(): void
    {
        $before = $this->applicationCount();
        foreach ([
            [['languages' => ''], 'Заповніть поле «Мови»'],
            [['consent' => ''], 'Потрібна згода'],
            [['email' => 'not-an-email'], 'Перевірте email'],
            [['phone' => '12'], 'Перевірте номер телефону'],
        ] as [$override, $message]) {
            $this->submit(array_merge($this->valid(), $override), []);
            self::assertSame($before, $this->applicationCount(), $message);
            self::assertStringContainsString($message, (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent());
        }
        $this->submit($this->valid(), [$this->file('<?php echo 1;', 'cv.pdf')]);
        self::assertSame($before, $this->applicationCount(), 'файл не того типу');
        self::assertSame(404, $this->http('POST', 'https://' . $this->apex . '/application/nope', [])->getStatusCode(), 'невідома форма');
    }

    /** @return array<string, mixed> */
    private function valid(): array
    {
        return ['name' => 'Іван', 'phone' => '+380 67 ' . random_int(100, 999) . ' 00 11', 'email' => 'ivan@example.test', 'languages' => 'німецька', 'consent' => '1'];
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<UploadedFile>   $files
     */
    private function submit(array $fields, array $files): Response
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"[^>]*>\s*<input type="text" name="website"[^>]*>\s*<input type="hidden" name="context"/', $page, $m), 'токен анкети');

        return $this->http('POST', 'https://' . $this->apex . '/application/translator', ['_token' => html_entity_decode($m[1])] + $fields,
            ['REMOTE_ADDR' => '10.6.' . random_int(0, 255) . '.' . random_int(1, 254), 'HTTP_REFERER' => 'https://' . $this->apex . '/contacts/'], ['files' => $files]);
    }

    private function last(): ServiceApplication
    {
        $this->em()->clear();
        $a = $this->em()->getRepository(ServiceApplication::class)->findOneBy(['city' => $this->apexCityId], ['id' => 'DESC']);
        self::assertInstanceOf(ServiceApplication::class, $a);

        return $a;
    }

    private function applicationCount(): int
    {
        return \count($this->em()->getRepository(ServiceApplication::class)->findBy(['city' => $this->apexCityId]));
    }

    private function docx(string $name): UploadedFile
    {
        // мінімальний zip з [Content_Types].xml — finfo бачить DOCX (або zip — тоді вирішує розширення)
        $path = (string) tempnam(sys_get_temp_dir(), 'ci-app-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        $zip->close();
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    private function file(string $content, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ci-app-');
        file_put_contents($path, $content);
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }
}
