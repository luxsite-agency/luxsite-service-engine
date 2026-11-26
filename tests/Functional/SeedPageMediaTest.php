<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServicePage;
use App\Entity\ServiceSite;
use App\ServiceSite\CustomFields;
use App\Tests\Support\PageSeedKernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

/**
 * app:site:seed для сторінок app.pages: `extra` (значення app.custom_fields.page), обкладинка `cover` і галерея `gallery`
 * з файлів паку (копія в media/service_page під унікальним іменем), англійський підпис галереї `caption_en`.
 */
final class SeedPageMediaTest extends HttpKernelTestCase
{
    private string $s;
    private string $pack;
    private string $host;
    private string $media;
    /** @var list<string> файли медіа, створені тестом */
    private array $created = [];

    protected static function getKernelClass(): string
    {
        return PageSeedKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->s = bin2hex(random_bytes(4));
        $this->host = "seed-pg-{$this->s}.example";
        $this->pack = sys_get_temp_dir() . '/seed-pack-' . $this->s;
        $this->media = static::getContainer()->getParameter('kernel.project_dir') . '/public/media/service_page';
        mkdir($this->pack . '/content/img', 0o777, true);
        foreach (['cover' => 10, 'front' => 60, 'kitchen' => 120, 'pool' => 180] as $name => $shade) {
            $im = imagecreatetruecolor(40, 30);
            imagefill($im, 0, 0, (int) imagecolorallocate($im, $shade, 255 - $shade, $shade));
            imagejpeg($im, "{$this->pack}/content/img/$name.jpg");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->media . '/*') ?: [] as $f) {
            if (\in_array(basename($f), $this->created, true)) {
                @unlink($f);
            }
        }
        (new Filesystem())->remove($this->pack);
        parent::tearDown();
    }

    /** A1: pages[].extra → service_page.extra; custom_fields(page, 'en') їх повертає */
    public function testSeedWritesExtraFields(): void
    {
        self::assertSame(0, $this->seed(<<<YAML
            pages:
              - slug: homes-{$this->s}
                title_en: Homes
              - slug: edge-{$this->s}
                parent: homes-{$this->s}
                title_en: 390 Edge Of Woods
                extra: {price: 11950000, village: Water Mill, note: {uk: 'Нова', en: 'New build'}, zip: ''}
            YAML, $out), $out);

        $page = $this->page("edge-{$this->s}");
        self::assertSame(
            ['price' => ['en' => '11950000'], 'village' => ['en' => 'Water Mill'], 'note' => ['uk' => 'Нова', 'en' => 'New build']],
            json_decode((string) $this->em()->getConnection()->fetchOne('SELECT extra FROM service_page WHERE id = ?', [$page->getId()]), true),
            'скаляр — значення мовами розгортання (app.locales: [en]), мапа — як є, порожнє не пишеться',
        );
        $shown = [];
        foreach (static::getContainer()->get(CustomFields::class)->display($page, 'en') as $f) {
            $shown[$f['label']] = $f['value'];
        }
        self::assertSame(['Price' => '11950000', 'Village' => 'Water Mill', 'Note' => 'New build'], $shown);

        // повторний seed з іншим значенням оновлює ключі з файлу й не чіпає решту (внесене в адмінці)
        self::assertSame(0, $this->seed(<<<YAML
            pages:
              - {slug: edge-{$this->s}, title_en: 390 Edge Of Woods, extra: {price: 9990000, village: null}}
            YAML, $out), $out);
        self::assertSame(['price' => ['en' => '9990000'], 'note' => ['uk' => 'Нова', 'en' => 'New build']], $this->page("edge-{$this->s}")->getExtra());
    }

    /** A2: cover і gallery → image_name, рядки галереї й файли в media; повторний seed нічого не дублює */
    public function testSeedCopiesCoverAndGalleryOnce(): void
    {
        $yaml = <<<YAML
            pages:
              - slug: edge-{$this->s}
                title_en: 390 Edge Of Woods
                cover: {file: img/cover.jpg, alt: 'Front of the house'}
                gallery:
                  - {file: img/front.jpg, caption: 'Вид спереду', caption_en: 'Front view'}
                  - {file: img/kitchen.jpg, caption: 'Kitchen'}
                  - {file: img/pool.jpg}
            YAML;
        $before = $this->mediaFiles();
        self::assertSame(0, $this->seed($yaml, $out, ['--dry-run' => true]), $out);
        self::assertSame($before, $this->mediaFiles(), 'dry-run файлів не копіює');

        self::assertSame(0, $this->seed($yaml, $out), $out);
        $page = $this->page("edge-{$this->s}");
        self::assertMatchesRegularExpression('/^cover-[0-9a-z.]+\.jpg$/', (string) $page->getImageName(), 'обкладинка під унікальним іменем (Vich)');
        self::assertSame('Front of the house', $page->getImageAlt());
        self::assertFileEquals("{$this->pack}/content/img/cover.jpg", $this->media . '/' . $page->getImageName());
        $rows = $this->gallery($page);
        self::assertSame(['Вид спереду', 'Kitchen', null], array_column($rows, 'caption_uk'));
        self::assertSame(['Front view', null, null], array_column($rows, 'caption_en'));
        self::assertSame(['10', '20', '30'], array_map('strval', array_column($rows, 'position')));
        foreach (['front', 'kitchen', 'pool'] as $i => $name) {
            self::assertFileEquals("{$this->pack}/content/img/$name.jpg", $this->media . '/' . $rows[$i]['image_name']);
        }
        self::assertFileExists("{$this->pack}/content/img/front.jpg", 'файл паку лишається на місці (копія, не перенос)');
        self::assertCount(\count($before) + 4, $after = $this->mediaFiles());

        // повторний seed: ті самі 3 рядки й ті самі файли; змінений підпис оновлюється
        self::assertSame(0, $this->seed(str_replace("caption: 'Kitchen'", "caption: 'Chef kitchen'", $yaml), $out), $out);
        $again = $this->gallery($this->page("edge-{$this->s}"));
        self::assertCount(3, $again, 'галерея не дублюється');
        self::assertSame(array_column($rows, 'image_name'), array_column($again, 'image_name'));
        self::assertSame('Chef kitchen', $again[1]['caption_uk']);
        self::assertSame($page->getImageName(), $this->page("edge-{$this->s}")->getImageName(), 'та сама обкладинка не перезаливається');
        self::assertSame($after, $this->mediaFiles(), 'нових файлів нема');
    }

    public function testSeedRejectsMissingOrOutsideFile(): void
    {
        foreach (['img/none.jpg' => 'нема файлу', '../../etc/passwd' => 'нема файлу'] as $file => $message) {
            self::assertSame(1, $this->seed(<<<YAML
                pages:
                  - {slug: bad-{$this->s}, title_en: Bad, gallery: [{file: '$file'}]}
                YAML, $out), $out);
            self::assertStringContainsString($message, $out);
        }
        $this->em()->clear();
        self::assertNull($this->em()->getRepository(ServicePage::class)->findOneBy(['slugUk' => "bad-{$this->s}"]), 'сторінку без файлу не створено');
    }

    /** A3: на сторінці en — caption_en; без нього — caption_uk */
    public function testGalleryCaptionFollowsLocaleWithFallback(): void
    {
        self::assertSame(0, $this->seed(<<<YAML
            pages:
              - slug: edge-{$this->s}
                title_en: 390 Edge Of Woods
                gallery:
                  - {file: img/front.jpg, caption: 'Вид спереду', caption_en: 'Front view'}
                  - {file: img/kitchen.jpg, caption: 'Кухня без перекладу'}
            YAML, $out), $out);

        $response = $this->http('GET', "https://{$this->host}/edge-{$this->s}/");
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<figcaption>Front view</figcaption>', $html);
        self::assertStringContainsString('alt="Front view"', $html);
        self::assertStringNotContainsString('Вид спереду', $html);
        self::assertStringContainsString('<figcaption>Кухня без перекладу</figcaption>', $html, 'нема caption_en → український підпис');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function seed(string $pages, ?string &$out, array $options = []): int
    {
        $file = $this->pack . '/content/seed.yaml';
        file_put_contents($file, "site: {slug: seed-pg-{$this->s}, name: 'Seed pages', apex: {$this->host}}\n"
            . "cities:\n  - {slug: main, host: {$this->host}, apex: true, name_nom_uk: 'Hamptons', name_nom_en: 'Hamptons'}\n" . $pages . "\n");
        $app = new Application(static::$kernel);
        $app->setAutoExit(false);
        $app->setCatchExceptions(true);
        $known = $this->mediaFiles();
        $code = $app->run(new ArrayInput(['command' => 'app:site:seed', 'file' => $file] + $options), $buffer = new BufferedOutput());
        $this->created = array_merge($this->created, array_values(array_diff($this->mediaFiles(), $known)));
        $out = $buffer->fetch();
        $this->em()->clear();

        return $code;
    }

    private function page(string $slug): ServicePage
    {
        $this->em()->clear();
        $site = $this->em()->getRepository(ServiceSite::class)->findOneBy(['slug' => "seed-pg-{$this->s}"]);
        self::assertNotNull($site);
        $page = $this->em()->getRepository(ServicePage::class)->findOneBy(['site' => $site, 'slugUk' => $slug]);
        self::assertNotNull($page, 'сторінка ' . $slug);

        return $page;
    }

    /** @return list<array<string, mixed>> */
    private function gallery(ServicePage $page): array
    {
        return $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM service_page_image WHERE page_id = ? ORDER BY position, id', [$page->getId()]);
    }

    /** @return list<string> */
    private function mediaFiles(): array
    {
        $files = array_map('basename', glob($this->media . '/*') ?: []);
        sort($files);

        return $files;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }
}
