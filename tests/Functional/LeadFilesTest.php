<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServiceLead;
use App\Entity\ServiceLeadFile;
use App\ServiceSite\LeadFiles;
use App\Tests\Support\LeadFilesKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Файли в заявці (app.lead_files.enabled, LeadFilesKernel): форма multipart з полем files[]; фото й PDF зберігаються поза
 * public/ під випадковими іменами; тип — за вмістом; ліміти кількості й розміру; файл віддається лише модератору адмінки;
 * видалення заявки прибирає файли з диска; prune — сироти.
 */
final class LeadFilesTest extends SeoTestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected static function getKernelClass(): string
    {
        return LeadFilesKernel::class;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function testFormHasFileField(): void
    {
        $html = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="files[]" multiple accept="image/jpeg,', $html);
    }

    public function testFilesStoredPrivatelyAndServedToModeratorOnly(): void
    {
        $r = $this->submit([$this->png('скан паспорта.png'), $this->pdf('dyplom.pdf')], ['service' => 'Апостиль на диплом', 'context' => 'картка ціни']);
        self::assertSame(302, $r->getStatusCode(), (string) $r->getContent());
        self::assertSame('/thank-you/?src=form', $r->headers->get('Location'));

        $lead = $this->lastLead();
        self::assertSame('Апостиль на диплом', $lead->getService());
        self::assertSame('картка ціни', $lead->getContext());
        $files = $lead->getFiles()->toArray();
        self::assertCount(2, $files);
        self::assertSame('скан паспорта.png', $files[0]->getOriginalName());
        self::assertSame('image/png', $files[0]->getMimeType());
        self::assertSame('application/pdf', $files[1]->getMimeType());
        $store = static::getContainer()->get(LeadFiles::class);
        foreach ($files as $file) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.(png|pdf)$/', $file->getStoredName());
            $path = $store->path($file);
            self::assertNotNull($path, 'файл на диску');
            self::assertStringNotContainsString('/public/', str_replace('\\', '/', (string) $path), 'поза public/');
        }

        $url = 'https://' . $this->adminHost . '/admin/lead-file/' . $files[0]->getId();
        $anon = $this->http('GET', $url);
        self::assertContains($anon->getStatusCode(), [302, 401], 'без входу — не віддаємо');
        self::assertSame(404, $this->http('GET', 'https://' . $this->apex . '/admin/lead-file/' . $files[0]->getId())->getStatusCode(), 'на хості сайту маршруту нема');
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        $ok = $this->http('GET', $url);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('image/png', $ok->headers->get('Content-Type'));
        self::assertSame('nosniff', $ok->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', (string) $ok->headers->get('Content-Security-Policy'));

        $detail = (string) $this->http('GET', 'https://' . $this->adminHost . '/admin/service-lead/' . $lead->getId())->getContent();
        self::assertStringContainsString('/admin/lead-file/' . $files[0]->getId(), $detail, 'посилання в перегляді заявки');
        self::assertStringContainsString('скан паспорта.png', $detail);

        // видалення заявки (ORM) — файли зникають з диска
        $paths = array_map(static fn (ServiceLeadFile $f): string => (string) $store->path($f), $files);
        $em = $this->em();
        $em->remove($em->find(ServiceLead::class, $lead->getId()));
        $em->flush();
        foreach ($paths as $p) {
            self::assertFileDoesNotExist($p);
        }
    }

    public function testRejectsWrongTypeTooManyAndTooBig(): void
    {
        $before = $this->leadCount();
        // PDF лише за іменем: вміст — текст
        $fake = $this->tmpFile("not a pdf\n", 'dyplom.pdf');
        $r = $this->submit([$fake]);
        self::assertSame(302, $r->getStatusCode());
        self::assertStringNotContainsString('thank-you', (string) $r->headers->get('Location'));
        self::assertSame($before, $this->leadCount(), 'заявки нема');
        self::assertStringContainsString('можна лише фото або PDF', $this->flashes());

        $this->submit([$this->png('1.png'), $this->png('2.png'), $this->png('3.png'), $this->png('4.png')]);
        self::assertSame($before, $this->leadCount());
        self::assertStringContainsString('не більше 3 файлів', $this->flashes());

        $big = $this->tmpFile("%PDF-1.4\n" . str_repeat('0', 1_100_000), 'big.pdf');
        $this->submit([$big]);
        self::assertSame($before, $this->leadCount());
        self::assertStringContainsString('завеликий', $this->flashes());

        // без файлів форма працює як була
        self::assertSame(302, $this->submit([])->getStatusCode());
        self::assertSame($before + 1, $this->leadCount());
        self::assertCount(0, $this->lastLead()->getFiles());
    }

    public function testPruneRemovesOrphans(): void
    {
        $dir = static::getContainer()->get(LeadFiles::class)->dir();
        @mkdir($dir, 0700, true);
        $orphan = $dir . '/' . str_repeat('a', 32) . '.pdf';
        file_put_contents($orphan, '%PDF');
        touch($orphan, time() - 2 * 86400);
        $tester = new CommandTester((new Application(static::$kernel))->find('app:lead-files:prune'));
        self::assertSame(0, $tester->execute([]));
        self::assertFileDoesNotExist($orphan);
        self::assertStringContainsString('без запису: 1', $tester->getDisplay());
    }

    /**
     * @param list<UploadedFile>    $files
     * @param array<string, string> $extra
     */
    private function submit(array $files, array $extra = []): Response
    {
        $page = (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
        self::assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $page, $m));

        return $this->http('POST', 'https://' . $this->apex . '/lead', [
            '_token' => html_entity_decode($m[1]),
            'phone' => '+380 67 ' . random_int(100, 999) . ' 11 22',
            'name' => 'Тест',
        ] + $extra, ['REMOTE_ADDR' => '10.8.' . random_int(0, 255) . '.' . random_int(1, 254), 'HTTP_REFERER' => 'https://' . $this->apex . '/contacts/'], ['files' => $files]);
    }

    private function flashes(): string
    {
        return (string) $this->http('GET', 'https://' . $this->apex . '/contacts/')->getContent();
    }

    private function lastLead(): ServiceLead
    {
        $this->em()->clear();
        $lead = $this->em()->getRepository(ServiceLead::class)->findOneBy(['city' => $this->apexCityId], ['id' => 'DESC']);
        self::assertInstanceOf(ServiceLead::class, $lead);

        return $lead;
    }

    private function leadCount(): int
    {
        return \count($this->em()->getRepository(ServiceLead::class)->findBy(['city' => $this->apexCityId]));
    }

    private function png(string $name): UploadedFile
    {
        // 1×1 PNG
        return $this->tmpFile((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true), $name);
    }

    private function pdf(string $name): UploadedFile
    {
        return $this->tmpFile("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", $name);
    }

    private function tmpFile(string $content, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ci-lead-');
        file_put_contents($path, $content);
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }
}
