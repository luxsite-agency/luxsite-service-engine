<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use App\AdminApi\Auth\ApiTokenContext;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceSiteRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Cache\FilesystemCache;
use Twig\Environment;

/**
 * Жива правка дизайну сайту під персональним токеном (власник 15.10.2026: «сайт не зламають, тільки дизайн; ризики є,
 * але мінімальні»). Файли живуть у <root>/shared/theme (переживають релізи): templates/** — Twig-перекриття, що Twig
 * бере ПЕРШИМИ (перед templates/site паку й templates рушія), public/theme.css — додатковий CSS (/theme-live/theme.css).
 * Запобіжники: лише services/** і partials/** (*.twig) та theme.css; шаблон до запису проходить ThemeTemplateGuard —
 * синтаксис і явний allowlist (LiveThemePolicy: без app.request.server, _context, attribute(), render(), controller(),
 * include_only() — порт із shop-engine PR #108, 22.10.2026); після запису рендеряться ключові сторінки сайту через HTTP —
 * 5xx чи обрив HTML → файл повертається як був; кожна версія — в history/ (відкат theme_revert); компільований кеш
 * шаблону скидається одразу. put/delete/revert — під одним замком на живу тему сайту (flock у var/theme): два записи
 * паралельно не відкотять один одного, другий чекає до app.theme_lock_wait с, далі 409. У git паку ці правки не
 * потрапляють — перенести туди (templates/ паку) може розробник: theme_get віддає поточний текст.
 */
final class ThemeService
{
    public const string CSS = 'theme.css';
    /** Імена шаблонів, які можна перекрити (і на які живий шаблон може посилатися — ThemeTemplateGuard). */
    public const string TEMPLATE_RE = '#^(services|partials)/[A-Za-z0-9_./-]+\.twig$#';
    /** Замок довший за найгірший smoke (sitemap 10 с + 7 сторінок × 15 с); flock звільняється й зі смертю процесу. */
    private const int LOCK_TTL = 180;
    private const int MAX_BYTES = 512 * 1024;
    private const int HISTORY_KEEP = 20;

    public function __construct(
        private readonly Environment $twig,
        private readonly ThemeTemplateGuard $guard,
        #[Autowire(service: 'app.theme_http_client')] private readonly HttpClientInterface $http,
        #[Autowire(service: 'app.theme_lock_factory')] private readonly LockFactory $locks,
        private readonly ServiceSiteRepository $sites,
        private readonly ServiceCityRepository $cities,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly ApiTokenContext $context,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%app.theme_lock_wait%')] private readonly int $lockWait = 30,
    ) {
    }

    /** Де лежать живі файли: var/theme → <root>/shared/theme (симлінк з bin/site, на стенді — mount). */
    public function root(): string
    {
        return $this->projectDir . '/var/theme';
    }

    /**
     * Що можна перекрити і що перекрито: шаблони рушія (services/**, partials/**) з позначкою, звідки береться поточна
     * версія (live | pack | engine), плюс theme.css.
     *
     * @return array<string, mixed>
     */
    public function list(string $siteSlug): array
    {
        $this->requireScope();
        $this->site($siteSlug);
        $rows = [];
        foreach ($this->engineTemplates() as $rel) {
            $rows[] = ['path' => $rel, 'source' => $this->sourceOf($rel), 'history' => \count($this->historyOf($rel))];
        }
        // власні partial-и паку/живі, яких у рушії нема
        foreach ($this->filesUnder($this->root() . '/templates') as $rel) {
            if (!\in_array($rel, array_column($rows, 'path'), true)) {
                $rows[] = ['path' => $rel, 'source' => 'live', 'history' => \count($this->historyOf($rel)), 'note' => 'власний файл (у рушії нема)'];
            }
        }
        $rows[] = ['path' => self::CSS, 'source' => is_file($this->livePath(self::CSS)) ? 'live' : 'none', 'history' => \count($this->historyOf(self::CSS)), 'note' => 'додатковий CSS після стилів рушія й паку'];

        return ['site' => $siteSlug, 'root' => 'shared/theme', 'files' => $rows, 'rules' => 'Лише services/** і partials/** (*.twig) та theme.css. Шаблони рушія доступні як @engine/… для {% extends %}. ' . LiveThemePolicy::summary() . ' Після put сторінки рендеряться автоматично; провал = файл повертається.'];
    }

    /** @return array<string, mixed> поточний текст і звідки він */
    public function get(string $siteSlug, string $path): array
    {
        $this->requireScope();
        $this->site($siteSlug);
        $rel = $this->normalize($path);
        $source = $this->sourceOf($rel);
        $file = match ($source) {
            'live' => $this->livePath($rel),
            'pack' => $this->projectDir . '/templates/site/' . $rel,
            'engine' => $this->projectDir . '/templates/' . $rel,
            default => null,
        };

        return ['path' => $rel, 'source' => $source, 'content' => $file === null ? null : (string) file_get_contents($file), 'history' => $this->historyOf($rel)];
    }

    /**
     * Записати живий файл: страж шаблону (синтаксис і allowlist) → історія → запис → скидання кешу → рендер ключових
     * сторінок → при провалі повернення попередньої версії. Усе — під замком живої теми.
     *
     * @return array<string, mixed>
     */
    public function put(string $siteSlug, string $path, string $content): array
    {
        $this->requireScope();
        $site = $this->site($siteSlug);
        $rel = $this->normalize($path);

        $this->validate($rel, $content); // до замка: відхилений шаблон не чекає чужого запису

        return $this->locked(fn (): array => $this->doPut($site, $rel, $content));
    }

    /** Розмір і страж шаблону (синтаксис + allowlist LiveThemePolicy); theme.css стражем не перевіряється. */
    private function validate(string $rel, string $content): void
    {
        if (\strlen($content) > self::MAX_BYTES) {
            throw new ApiProblem(sprintf('Файл більший за %d КБ', self::MAX_BYTES / 1024), 422);
        }
        if ($rel !== self::CSS) {
            $problems = $this->guard->check($rel, $content);
            if ($problems !== []) {
                $this->logger->warning('theme.put_denied', ['path' => $rel, 'problems' => \count($problems)] + $this->who());
                throw new ApiProblem('Шаблон не пройшов перевірку живої теми: ' . implode('; ', $problems), 422);
            }
        }
    }

    /** Запис уже перевіреного вмісту; викликати лише під locked(). @return array<string, mixed> */
    private function doPut(\App\Entity\ServiceSite $site, string $rel, string $content): array
    {
        $file = $this->livePath($rel);
        $previous = is_file($file) ? (string) file_get_contents($file) : null;
        $this->snapshot($rel, $previous);
        if (!is_dir(\dirname($file)) && !@mkdir(\dirname($file), 0775, true) && !is_dir(\dirname($file))) {
            throw new ApiProblem('Не вдалося створити теку для ' . $rel, 500);
        }
        file_put_contents($file, $content);
        $this->invalidate($rel);
        $failed = $this->smoke($site);
        if ($failed !== null) {
            // повернути як було
            if ($previous === null) {
                @unlink($file);
            } else {
                file_put_contents($file, $previous);
            }
            $this->invalidate($rel);
            $this->logger->warning('theme.put_rejected', ['path' => $rel, 'error' => $failed] + $this->who());
            throw new ApiProblem('Сторінка не рендериться після зміни, файл повернуто: ' . $failed, 422);
        }
        $this->logger->info('theme.put', ['path' => $rel, 'bytes' => \strlen($content), 'previous' => $previous !== null] + $this->who());

        return ['path' => $rel, 'source' => 'live', 'bytes' => \strlen($content), 'smoke' => 'ok', 'history' => $this->historyOf($rel)];
    }

    /** Прибрати живе перекриття: далі береться пак або рушій. @return array<string, mixed> */
    public function delete(string $siteSlug, string $path): array
    {
        $this->requireScope();
        $this->site($siteSlug);
        $rel = $this->normalize($path);

        return $this->locked(function () use ($rel): array {
            $file = $this->livePath($rel);
            if (!is_file($file)) {
                throw new ApiProblem('Живого перекриття для ' . $rel . ' нема', 404);
            }
            $this->snapshot($rel, (string) file_get_contents($file));
            unlink($file);
            $this->invalidate($rel);
            $this->logger->info('theme.delete', ['path' => $rel] + $this->who());

            return ['path' => $rel, 'source' => $this->sourceOf($rel), 'history' => $this->historyOf($rel)];
        });
    }

    /**
     * Повернути версію з історії (version = ім'я з historyOf) з тими самими перевірками, що put, під тим самим замком
     * (doPut, не put: другий flock того ж файлу з цього процесу чекав би сам на себе). Стара версія, записана до стража,
     * із забороненими конструкціями не повернеться — 422.
     *
     * @return array<string, mixed>
     */
    public function revert(string $siteSlug, string $path, string $version): array
    {
        $this->requireScope();
        $site = $this->site($siteSlug);
        $rel = $this->normalize($path);
        if (!preg_match('/^\d{8}-\d{6}$/', $version)) {
            throw new ApiProblem('version — як у history: YYYYMMDD-HHMMSS', 422);
        }

        return $this->locked(function () use ($site, $rel, $version): array {
            $file = $this->historyDir($rel) . '/' . $version;
            if (!is_file($file)) {
                throw new ApiProblem('Версії нема: ' . $version, 404);
            }

            $content = (string) file_get_contents($file);
            $this->validate($rel, $content);

            return $this->doPut($site, $rel, $content);
        });
    }

    /**
     * Один замок на всю живу тему сайту (smoke рендерить усе): без нього два PUT паралельно знімали б той самий previous,
     * і провал першого записав би previous поверх успішного другого. Неблокуюче acquire з паузами до app.theme_lock_wait с
     * — fpm-воркер не висне назавжди; не дочекались — 409, клієнт повторює.
     *
     * @template T
     *
     * @param callable(): T $op
     *
     * @return T
     */
    private function locked(callable $op): mixed
    {
        $lock = $this->locks->createLock('theme-live', self::LOCK_TTL);
        $deadline = microtime(true) + max(0, $this->lockWait);
        while (!$lock->acquire()) {
            if (microtime(true) >= $deadline) {
                $this->logger->warning('theme.locked', $this->who());
                throw new ApiProblem('Живу тему зараз змінює інший запит — повторіть за кілька секунд', 409);
            }
            usleep(200_000);
        }
        try {
            return $op();
        } finally {
            $lock->release();
        }
    }

    private function requireScope(): void
    {
        if (!$this->auth->isGranted('scope:theme:write')) {
            throw new ApiProblem('Токену бракує дозволу theme:write або власнику — ролі для нього', 403);
        }
    }

    private function site(string $slug): \App\Entity\ServiceSite
    {
        return $this->sites->findActiveBySlug($slug) ?? throw new ApiProblem('Сайт не знайдено: ' . $slug, 404);
    }

    private function normalize(string $path): string
    {
        $rel = ltrim(str_replace('\\', '/', trim($path)), '/');
        $rel = preg_replace('#^templates/#', '', $rel) ?? $rel;
        if ($rel === self::CSS) {
            return $rel;
        }
        if (str_contains($rel, '..') || !preg_match(self::TEMPLATE_RE, $rel)) {
            throw new ApiProblem('Дозволені лише services/**.twig, partials/**.twig і theme.css', 422);
        }

        return $rel;
    }

    private function livePath(string $rel): string
    {
        return $rel === self::CSS ? $this->root() . '/public/' . self::CSS : $this->root() . '/templates/' . $rel;
    }

    private function sourceOf(string $rel): string
    {
        if (is_file($this->livePath($rel))) {
            return 'live';
        }
        if ($rel === self::CSS) {
            return 'none';
        }
        if (is_file($this->projectDir . '/templates/site/' . $rel)) {
            return 'pack';
        }

        return is_file($this->projectDir . '/templates/' . $rel) ? 'engine' : 'none';
    }

    /** @return list<string> */
    private function engineTemplates(): array
    {
        $out = [];
        foreach (['services', 'partials'] as $dir) {
            foreach ($this->filesUnder($this->projectDir . '/templates/' . $dir) as $rel) {
                $out[] = $dir . '/' . $rel;
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> відносні шляхи *.twig під текою */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.twig')) {
                $out[] = str_replace('\\', '/', substr($f->getPathname(), \strlen($dir) + 1));
            }
        }
        sort($out);

        return $out;
    }

    private function historyDir(string $rel): string
    {
        return $this->root() . '/history/' . str_replace('/', '__', $rel);
    }

    /** @return list<string> версії, новіші першими */
    private function historyOf(string $rel): array
    {
        $dir = $this->historyDir($rel);
        if (!is_dir($dir)) {
            return [];
        }
        $v = array_values(array_filter(scandir($dir) ?: [], static fn ($f) => preg_match('/^\d{8}-\d{6}$/', $f) === 1));
        rsort($v);

        return $v;
    }

    private function snapshot(string $rel, ?string $previous): void
    {
        if ($previous === null) {
            return;
        }
        $dir = $this->historyDir($rel);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = date('Ymd-His');
        $i = 0;
        while (is_file($dir . '/' . $name)) {
            $name = date('Ymd-His', time() + ++$i);
        }
        file_put_contents($dir . '/' . $name, $previous);
        foreach (\array_slice($this->historyOf($rel), self::HISTORY_KEEP) as $old) {
            @unlink($dir . '/' . $old);
        }
    }

    /** Скинути компільований шаблон: у prod auto_reload вимкнено, без цього стара версія жила б до cache:clear. */
    private function invalidate(string $rel): void
    {
        if ($rel === self::CSS) {
            return;
        }
        $cache = $this->twig->getCache(false);
        if ($cache instanceof FilesystemCache) {
            $key = $cache->generateKey($rel, $this->twig->getTemplateClass($rel));
            if (is_file($key)) {
                @unlink($key);
                if (\function_exists('opcache_invalidate')) {
                    @opcache_invalidate($key, true);
                }
            }
        }
    }

    /** Рендер ключових сторінок сайту через HTTP (nginx+fpm, як у відвідувача); null = усе гаразд, інакше опис провалу. */
    private function smoke(\App\Entity\ServiceSite $site): ?string
    {
        $apex = $this->cities->findActiveApex($site);
        if ($apex === null) {
            return null;
        }
        $base = 'https://' . $apex->getHost();
        $urls = ['/', '/services/', '/districts/', '/faq/', '/blog/'];
        try {
            $map = $this->http->request('GET', $base . '/sitemap.xml', ['timeout' => 10])->getContent(false);
            foreach (['~<loc>' . preg_quote($base, '~') . '(/services/[^<]+/)</loc>~', '~<loc>' . preg_quote($base, '~') . '(/districts/[^<]+/)</loc>~'] as $re) {
                if (preg_match($re, $map, $m) && $m[1] !== '/services/' && $m[1] !== '/districts/') {
                    $urls[] = $m[1];
                }
            }
        } catch (\Throwable) {
            // без sitemap — лише базові сторінки
        }
        foreach ($urls as $u) {
            try {
                $r = $this->http->request('GET', $base . $u . '?theme_check=' . random_int(1, 999999), ['timeout' => 15, 'headers' => ['User-Agent' => 'service-engine theme-check']]);
                $code = $r->getStatusCode();
                $body = $r->getContent(false);
            } catch (\Throwable $e) {
                return $u . ': ' . $e->getMessage();
            }
            if ($code >= 500) {
                return sprintf('%s → %d', $u, $code);
            }
            if ($code === 200 && !str_contains($body, '</html>')) {
                return $u . ': обірваний HTML';
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function who(): array
    {
        $t = $this->context->current();

        return ['user_id' => $t?->getUser()->getId(), 'user' => $t?->getUser()->getEmail(), 'token_id' => $t?->getId(), 'token_prefix' => $t?->getPrefix()];
    }
}
