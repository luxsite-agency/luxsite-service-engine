<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceLeadFile;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Файли заявок (app.lead_files): фото чи PDF документа з форми. Вимкнено за замовчуванням — тоді поле files[] форма не
 * показує, а /lead файли ігнорує. Перевірка — за вмістом (MIME з finfo, не з імені й не з заголовка браузера), кількість і
 * розміри — з параметра. Зберігання — поза public/: тека app.lead_files_dir (типово var/lead-files, bin/site deploy
 * веде її в shared/lead-files), ім'я на диску випадкове. Видалення запису (з заявкою або prune) прибирає й файл.
 */
#[AsEntityListener(event: Events::postRemove, method: 'postRemove', entity: ServiceLeadFile::class)]
final class LeadFiles
{
    /** Розширення на диску за MIME (лише ці типи можна дозволити в app.lead_files.mime) */
    public const array EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/heic' => 'heic',
        'image/heif' => 'heif', 'application/pdf' => 'pdf', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    private const array DEFAULTS = [
        'enabled' => false, 'max_files' => 5, 'max_file_mb' => 10, 'max_total_mb' => 25,
        'mime' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'application/pdf'],
        'retention_days' => 0,
    ];

    /** @var array{enabled: bool, max_files: int, max_file_mb: int|float, max_total_mb: int|float, mime: list<string>, retention_days: int} */
    private array $config;

    /** @param array<string, mixed> $config app.lead_files */
    public function __construct(
        #[Autowire(param: 'app.lead_files')] array $config,
        #[Autowire(param: 'app.lead_files_dir')] private readonly string $dir,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'ADMIN_HOST')] private readonly string $adminHost = '',
    ) {
        $this->config = $config + self::DEFAULTS;
        foreach ($this->config['mime'] as $mime) {
            if (!isset(self::EXTENSIONS[$mime])) {
                throw new \InvalidArgumentException(sprintf('app.lead_files.mime: «%s» не підтримується (%s).', $mime, implode(', ', array_keys(self::EXTENSIONS))));
            }
        }
    }

    /**
     * Ті самі тека й перевірки з іншими лімітами (файли анкети app.applications.<форма>.files): ключі, яких нема, — з
     * app.lead_files; enabled — завжди так.
     *
     * @param array<string, mixed> $config
     */
    public function withConfig(array $config): self
    {
        $copy = clone $this;
        $copy->config = ['enabled' => true] + $config + $this->config;
        foreach ($copy->config['mime'] as $mime) {
            if (!isset(self::EXTENSIONS[$mime])) {
                throw new \InvalidArgumentException(sprintf('Файли форми: MIME «%s» не підтримується (%s).', $mime, implode(', ', array_keys(self::EXTENSIONS))));
            }
        }

        return $copy;
    }

    public function enabled(): bool
    {
        return (bool) $this->config['enabled'];
    }

    /** @return array{enabled: bool, max_files: int, max_file_mb: int|float, max_total_mb: int|float, mime: list<string>, retention_days: int} */
    public function config(): array
    {
        return $this->config;
    }

    /** Значення атрибута accept для <input type="file"> */
    public function accept(): string
    {
        return implode(',', $this->config['mime']);
    }

    /**
     * Файли з запиту (files[] або один files), без порожніх слотів форми.
     *
     * @return list<UploadedFile>
     */
    public static function fromRequest(mixed $raw): array
    {
        $raw = $raw instanceof UploadedFile ? [$raw] : (\is_array($raw) ? $raw : []);

        return array_values(array_filter($raw, static fn ($f): bool => $f instanceof UploadedFile && $f->getError() !== \UPLOAD_ERR_NO_FILE));
    }

    /**
     * Помилка для людини або null. Порожній список — завжди null (файли необов'язкові).
     *
     * @param list<UploadedFile> $files
     */
    public function validate(array $files, string $locale): ?string
    {
        if ($files === []) {
            return null;
        }
        $ru = $locale === 'ru';
        $c = $this->config;
        if (\count($files) > $c['max_files']) {
            return sprintf($ru ? 'Можно приложить не больше %d файлов.' : 'Можна додати не більше %d файлів.', $c['max_files']);
        }
        $total = 0;
        foreach ($files as $file) {
            if (!$file->isValid()) {
                return \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                    ? sprintf($ru ? 'Файл «%s» слишком большой: до %s МБ.' : 'Файл «%s» завеликий: до %s МБ.', self::clientName($file), $c['max_file_mb'])
                    : sprintf($ru ? 'Файл «%s» не загрузился, попробуйте ещё раз.' : 'Файл «%s» не завантажився, спробуйте ще раз.', self::clientName($file));
            }
            $size = (int) $file->getSize();
            if ($size > $c['max_file_mb'] * 1048576) {
                return sprintf($ru ? 'Файл «%s» слишком большой: до %s МБ.' : 'Файл «%s» завеликий: до %s МБ.', self::clientName($file), $c['max_file_mb']);
            }
            $total += $size;
            // MIME — за вмістом файлу (finfo), не з імені й не з того, що надіслав браузер
            if (!\in_array(self::mime($file), $c['mime'], true)) {
                return sprintf($ru ? 'Файл «%s»: можно лишь %s.' : 'Файл «%s»: можна лише %s.', self::clientName($file), self::kinds($c['mime'], $ru));
            }
        }
        if ($total > $c['max_total_mb'] * 1048576) {
            return sprintf($ru ? 'Файлы вместе — до %s МБ.' : 'Файли разом — до %s МБ.', $c['max_total_mb']);
        }

        return null;
    }

    /**
     * Переносить перевірені файли в приватну теку; записи ServiceLeadFile — для заявки. Помилка диска — виняток (заявку з
     * файлами, які не збереглися, не приймаємо мовчки): контролер відкочує вже перенесені файли через discard().
     *
     * @param list<UploadedFile> $files
     *
     * @return list<ServiceLeadFile>
     */
    public function store(array $files): array
    {
        if ($files === []) {
            return [];
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Не вдалося створити теку файлів заявок.');
        }
        $stored = [];
        try {
            foreach ($files as $file) {
                $mime = self::mime($file);
                $name = bin2hex(random_bytes(16)) . '.' . self::EXTENSIONS[$mime];
                $size = (int) $file->getSize();
                $file->move($this->dir, $name);
                @chmod($this->dir . '/' . $name, 0600);
                $stored[] = (new ServiceLeadFile())
                    ->setOriginalName(self::clientName($file))
                    ->setStoredName($name)
                    ->setMimeType($mime)
                    ->setSize($size);
            }
        } catch (\Throwable $e) {
            $this->discard($stored);
            throw $e;
        }

        return $stored;
    }

    /** @param list<ServiceLeadFile> $files прибрати з диска (запис у БД не створився) */
    public function discard(array $files): void
    {
        foreach ($files as $file) {
            $this->unlink($file->getStoredName());
        }
    }

    /** Повний шлях файлу на диску; null — ім'я не наше (захист від ../) або файлу нема */
    public function path(ServiceLeadFile $file): ?string
    {
        $name = $file->getStoredName();
        if (preg_match('/^[0-9a-f]{32}\.[a-z]{3,4}$/', $name) !== 1) {
            return null;
        }
        $path = $this->dir . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /** Посилання на файл в адмінці (лист менеджеру, CRM): відкривається після входу модератора */
    public function adminUrl(ServiceLeadFile $file): string
    {
        return 'https://' . $this->adminHost . '/admin/lead-file/' . $file->getId();
    }

    /**
     * Рядки «назва (розмір): посилання» для листа й CRM; порожньо — файлів нема.
     *
     * @param iterable<ServiceLeadFile> $files
     *
     * @return list<string>
     */
    public function lines(iterable $files): array
    {
        $rows = [];
        foreach ($files as $file) {
            $rows[] = $file . ': ' . $this->adminUrl($file);
        }

        return $rows;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function postRemove(ServiceLeadFile $file): void
    {
        $this->unlink($file->getStoredName());
    }

    private function unlink(string $name): void
    {
        if (preg_match('/^[0-9a-f]{32}\.[a-z]{3,4}$/', $name) !== 1) {
            return;
        }
        $path = $this->dir . '/' . $name;
        if (is_file($path) && !@unlink($path)) {
            $this->logger->warning('Файл заявки не видалено з диска: {name}', ['name' => $name]);
        }
    }

    /**
     * MIME за вмістом. Word finfo бачить по-різному: DOCX — як zip, DOC — як OLE-контейнер; такі уточнюємо розширенням
     * (лише ці два випадки — інакше розширення нічого не вирішує).
     */
    private static function mime(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();
        $ext = strtolower(pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION));

        return match (true) {
            $ext === 'docx' && \in_array($mime, ['application/zip', 'application/octet-stream'], true) => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $ext === 'doc' && \in_array($mime, ['application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'], true) => 'application/msword',
            default => $mime,
        };
    }

    /** «фото або PDF», «фото, PDF або DOC/DOCX» — для повідомлення про недозволений тип */
    private static function kinds(array $mime, bool $ru): string
    {
        $kinds = [];
        foreach ($mime as $m) {
            $kinds[str_starts_with($m, 'image/') ? 'фото' : ($m === 'application/pdf' ? 'PDF' : 'DOC/DOCX')] = true;
        }
        $kinds = array_keys($kinds);
        $last = array_pop($kinds);

        return $kinds === [] ? (string) $last : implode(', ', $kinds) . ($ru ? ' или ' : ' або ') . $last;
    }

    /** Ім'я від людини без шляху й керівних символів, до 255 */
    private static function clientName(UploadedFile $file): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', basename(str_replace('\\', '/', $file->getClientOriginalName())));

        return mb_substr($name !== '' ? $name : 'file', 0, 255);
    }
}
