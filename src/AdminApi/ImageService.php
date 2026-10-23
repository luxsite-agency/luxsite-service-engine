<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use App\AdminApi\Auth\ApiTokenContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Vich\UploaderBundle\Handler\UploadHandler;

/**
 * Фото записів через admin API (REST PUT/DELETE …/image і MCP image_put/image_delete), дозвіл content:write.
 * Джерело — multipart-файл, URL (ImageFetcher, без SSRF) або base64. Запис — через сутність і Vich (setImageFile),
 * тому імена, видалення старого файлу й кеш Liip працюють як з адмінки; межі (≤10 МБ, JPEG/PNG/WebP, ≤8000 px) —
 * Assert\Image сутності через валідатор. MIME — за вмістом (finfo + getimagesize), SVG/GIF/HEIC — 422. Ім'я файлу
 * з запиту в шлях не потрапляє: лише слаг як підказка для SmartUniqueNamer. Кеш Liip старого файлу прибирає
 * VichLiipCacheSubscriber (vich_uploader.pre_remove — і для адмінки). Ліміт завантажень на токен
 * (limiter admin_api_image_token), кожна операція — у security_audit.
 */
final class ImageService
{
    /** = maxSize '10M' у Assert\Image сутностей (Symfony: M = 10^6) */
    public const int MAX_BYTES = 10_000_000;
    private const array ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const array REFUSED = [
        'image/svg+xml' => 'SVG (вектор зі скриптами — лише через адмінку як файл теми)',
        'image/gif' => 'GIF (анімація не обробляється Liip; конвертуйте у WebP/PNG)',
        'image/heic' => 'HEIC/HEIF (формат iPhone; конвертуйте у JPEG)', 'image/heif' => 'HEIC/HEIF (конвертуйте у JPEG)',
        'image/avif' => 'AVIF (конвертуйте у WebP/JPEG)', 'image/bmp' => 'BMP', 'image/tiff' => 'TIFF',
    ];

    public function __construct(
        private readonly ContentService $content,
        private readonly ImageInfo $info,
        private readonly ImageFetcher $fetcher,
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
        private readonly UploadHandler $uploads,
        private readonly SluggerInterface $slugger,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly ApiTokenContext $context,
        #[Autowire(service: 'limiter.admin_api_image_token')] private readonly RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'monolog.logger.security_audit')] private readonly LoggerInterface $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function putUpload(string $site, string $kind, int $id, ?UploadedFile $file, ?string $alt, ?string $altRu): array
    {
        return $this->put($site, $kind, $id, 'multipart', function () use ($file): UploadedFile {
            if ($file === null) {
                throw new ApiProblem('Очікується поле file (multipart/form-data) або JSON з url чи data', 400);
            }
            if (!$file->isValid()) {
                $tooBig = \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true);
                throw new ApiProblem('file: ' . $file->getErrorMessage(), $tooBig ? 413 : 422);
            }
            $this->assertSize((int) $file->getSize(), 'file');

            return $file;
        }, $alt, $altRu);
    }

    /** @return array<string, mixed> */
    public function putUrl(string $site, string $kind, int $id, string $url, ?string $alt, ?string $altRu): array
    {
        return $this->put($site, $kind, $id, 'url', function () use ($url): UploadedFile {
            $url = trim($url);
            if ($url === '' || \strlen($url) > 2048) {
                throw new ApiProblem('url: порожній або задовгий', 422);
            }
            $tmp = $this->fetcher->fetch($url, self::MAX_BYTES);
            $name = (string) (pathinfo((string) parse_url($url, \PHP_URL_PATH), \PATHINFO_FILENAME) ?: 'image');

            return $this->tempUpload($tmp, $name);
        }, $alt, $altRu);
    }

    /** @return array<string, mixed> */
    public function putBase64(string $site, string $kind, int $id, string $data, ?string $filename, ?string $alt, ?string $altRu): array
    {
        return $this->put($site, $kind, $id, 'base64', function () use ($data, $filename): UploadedFile {
            $data = preg_replace('~^data:[^;,]*;base64,~i', '', trim($data)) ?? '';
            // ліміт до декодування: base64 = 4/3 від розміру + відступ
            if (\strlen($data) > (int) ceil(self::MAX_BYTES * 4 / 3) + 16) {
                throw new ApiProblem(sprintf('data: понад %d МБ', intdiv(self::MAX_BYTES, 1_000_000)), 413);
            }
            $bin = base64_decode($data, true);
            if ($bin === false || $bin === '') {
                throw new ApiProblem('data: не base64', 422);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'img-b64-');
            if ($tmp === false || file_put_contents($tmp, $bin) === false) {
                throw new ApiProblem('Нема місця для тимчасового файлу', 500);
            }

            return $this->tempUpload($tmp, (string) (pathinfo((string) $filename, \PATHINFO_FILENAME) ?: 'image'));
        }, $alt, $altRu);
    }

    /** @return array<string, mixed> */
    public function delete(string $site, string $kind, int $id): array
    {
        $this->requireWrite();
        $kind = ImageInfo::kind($kind);
        [$siteEntity, $e] = $this->content->entity($site, $kind, $id);
        $old = $e->getImageName();
        if ($old === null || $old === '') {
            // ідемпотентно: бажаний стан уже є — звичайний результат, не помилка (у MCP це було б isError)
            return ['site' => $siteEntity->getSlug(), 'kind' => $kind, 'id' => $id, 'image' => null, 'deleted' => null];
        }
        $this->uploads->remove($e, 'imageFile');
        $e->setImageAlt(null);
        if (method_exists($e, 'setImageAltRu')) {
            $e->setImageAltRu(null);
        }
        $this->em->flush();
        $this->log('admin_api.image.delete', $kind, $id, ['site' => $siteEntity->getSlug(), 'name' => $old]);

        return ['site' => $siteEntity->getSlug(), 'kind' => $kind, 'id' => $id, 'image' => null, 'deleted' => $old];
    }

    /**
     * @param callable(): UploadedFile $source
     *
     * @return array<string, mixed>
     */
    private function put(string $site, string $kind, int $id, string $via, callable $source, ?string $alt, ?string $altRu): array
    {
        $this->requireWrite();
        $kind = ImageInfo::kind($kind);
        [$siteEntity, $e] = $this->content->entity($site, $kind, $id);
        if ($altRu !== null && !method_exists($e, 'setImageAltRu')) {
            throw new ApiProblem('alt_ru є лише у posts; для ' . $kind . ' — поле alt', 422);
        }
        $this->throttle();
        $file = $source();
        try {
            $mime = $this->sniff($file->getPathname());
            $old = $e->getImageName();
            $e->setImageFile($file);
            $violations = $this->validator->validateProperty($e, 'imageFile');
            if (\count($violations) > 0) {
                $e->setImageFile(null);
                $messages = [];
                foreach ($violations as $v) {
                    $messages[] = (string) $v->getMessage();
                }
                throw new ApiProblem('Зображення відхилено: ' . implode(' ', array_unique($messages)), 422);
            }
            if ($alt !== null) {
                $e->setImageAlt(mb_substr(trim($alt), 0, 255) ?: null);
            }
            if ($altRu !== null) {
                $e->setImageAltRu(mb_substr(trim($altRu), 0, 255) ?: null);
            }
            $bytes = (int) $file->getSize();
            $this->em->flush(); // Vich: переносить файл, пише imageName, видаляє старий оригінал
        } finally {
            if (is_file($file->getPathname())) {
                @unlink($file->getPathname());
            }
        }
        $image = $this->info->describe($kind, $e, $siteEntity);
        $this->log('admin_api.image.put', $kind, $id, ['site' => $siteEntity->getSlug(), 'via' => $via, 'mime' => $mime, 'bytes' => $bytes, 'name' => $image['name'] ?? null, 'replaced' => $old]);

        return ['site' => $siteEntity->getSlug(), 'kind' => $kind, 'id' => $id, 'image' => $image];
    }

    /** MIME за вмістом: finfo і getimagesize мають зійтися на JPEG/PNG/WebP. */
    private function sniff(string $path): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);
        if (isset(self::REFUSED[$mime])) {
            throw new ApiProblem('Формат не приймається: ' . self::REFUSED[$mime] . '. Дозволено JPEG, PNG, WebP.', 422);
        }
        $size = @getimagesize($path);
        $gdMime = $size === false ? null : ($size['mime'] ?? null);
        if (!isset(self::ALLOWED[$mime]) || $gdMime !== $mime) {
            throw new ApiProblem(sprintf('Це не JPEG/PNG/WebP за вмістом (finfo: %s, getimagesize: %s); розширення й Content-Type не враховуються', $mime ?: '—', $gdMime ?? '—'), 422);
        }

        return $mime;
    }

    /** Тимчасовий файл → UploadedFile для Vich (test=true: звичайний rename, не move_uploaded_file). Ім'я — лише слаг. */
    private function tempUpload(string $tmp, string $hint): UploadedFile
    {
        $this->assertSize((int) filesize($tmp), 'data', $tmp);
        $mime = (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($tmp);
        $ext = self::ALLOWED[$mime] ?? 'bin';
        $slug = mb_substr(strtolower((string) $this->slugger->slug($hint)), 0, 60) ?: 'image';

        return new UploadedFile($tmp, $slug . '.' . $ext, $mime, null, true);
    }

    private function assertSize(int $bytes, string $field, ?string $cleanup = null): void
    {
        if ($bytes > self::MAX_BYTES) {
            if ($cleanup !== null) {
                @unlink($cleanup);
            }
            throw new ApiProblem(sprintf('%s: понад %d МБ (%d байт)', $field, intdiv(self::MAX_BYTES, 1_000_000), $bytes), 413);
        }
    }

    private function requireWrite(): void
    {
        if (!$this->auth->isGranted('scope:content:write')) {
            throw new ApiProblem('Токену бракує дозволу content:write або власнику — ролі для нього', 403);
        }
    }

    private function throttle(): void
    {
        $token = $this->context->current();
        $limit = $this->limiter->create('token-' . ($token?->getId() ?? 'none'))->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new ApiProblem('Забагато завантажень: ліміт на токен за хвилину вичерпано, повторіть за ' . $retry . ' с', 429, ['Retry-After' => (string) $retry]);
        }
    }

    /** @param array<string, mixed> $extra */
    private function log(string $event, string $kind, int $id, array $extra): void
    {
        $token = $this->context->current();
        $this->audit->info($event, $extra + [
            'kind' => $kind, 'id' => $id,
            'user_id' => $token?->getUser()->getId(), 'user' => $token?->getUser()->getEmail(),
            'token_id' => $token?->getId(), 'token_prefix' => $token?->getPrefix(),
        ]);
    }
}
