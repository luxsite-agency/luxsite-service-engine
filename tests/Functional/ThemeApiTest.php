<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Живий дизайн під токеном theme:write через REST (порт виправлень shop-engine PR #108, 22.10.2026): шаблон, що читає
 * секрети оточення чи викликає контролер, відхиляється до запису (422), сайт їх не показує; дозволений шаблон пишеться
 * й видно на сторінці; два записи не йдуть одночасно — другий чекає замок, а не дочекавшись — 409 без зміни файлу.
 * Рендер сторінок після запису — ThemeSmokeMock (config/services.yaml, when@test), сторінку сайту — запитом через ядро.
 */
final class ThemeApiTest extends HttpKernelTestCase
{
    private const string HOME = 'services/home.html.twig';

    private string $slug;
    private string $apex;
    private string $adminHost;
    private int $siteId;
    private string $email;
    private string $bearer;
    private string $themeRoot;

    protected function setUp(): void
    {
        $this->slug = 'ci-theme-' . bin2hex(random_bytes(3));
        $this->apex = $this->slug . '.example';
        $this->adminHost = 'admin.' . $this->apex;
        $_SERVER['ADMIN_HOST'] = $_ENV['ADMIN_HOST'] = $this->adminHost;
        putenv('ADMIN_HOST=' . $this->adminHost);
        self::bootKernel();

        $this->themeRoot = static::getContainer()->getParameter('kernel.project_dir') . '/var/theme';
        $this->cleanTheme();
        $em = $this->em();
        $site = (new ServiceSite())->setSlug($this->slug)->setName('CI Theme Site')->setApexDomain($this->apex);
        $em->persist($site);
        $em->persist((new ServiceCity())->setSite($site)->setHost($this->apex)->setSlug('main')->setIsApex(true)->setNameNomUk('Головне'));
        $this->email = $this->slug . '@example.test';
        $user = (new User())->setEmail($this->email)->setRoles(['ROLE_MODERATOR'])->setPassword('!');
        $em->persist($user);
        $em->flush();
        $this->siteId = (int) $site->getId();
        $this->bearer = static::getContainer()->get(AdminApiTokens::class)->issue($user, 'ci theme', [ApiScope::ThemeWrite], 1, $user)->plain;
        $em->clear();
    }

    protected function tearDown(): void
    {
        $this->cleanTheme();
        $em = $this->em();
        $em->clear();
        $site = $em->find(ServiceSite::class, $this->siteId);
        if ($site !== null) {
            $em->remove($site);
        }
        $user = $em->getRepository(User::class)->findOneBy(['email' => $this->email]);
        if ($user !== null) {
            $em->remove($user); // токени — ON DELETE CASCADE
        }
        $em->flush();
        parent::tearDown();
    }

    public function testLiveTemplateCannotReadSecretsOrRunControllers(): void
    {
        $vectors = [
            "{{ app.request.server.get('APP_SECRET') }}" => 'app.request.server',
            '{{ _context.app.request.server.all|json_encode }}' => '_context',
            "{{ attribute(app.request, 'ser' ~ 'ver').all|json_encode }}" => 'attribute',
            "{% set r = app %}{{ r.request.server.get('DATABASE_URL') }}" => 'app (рядок',
            '{% if app.request %}{{ app.request.headers.all|json_encode }}{% endif %}' => 'app.request.headers',
            '{% for x in [1] %}{{ loop.parent.app.request.server.all|json_encode }}{% endfor %}' => '.parent',
            "{{ render(controller('App\\\\Controller\\\\Admin\\\\DashboardController::index')) }}" => 'render',
            "{{ include_only(app.request.query.get('t')) }}" => 'include_only',
            "{% include app.request.query.get('t') %}" => 'не виразом',
            "{{ include('admin/dashboard.html.twig') }}" => 'admin/dashboard',
            "{{ ['id']|map('system')|join }}" => 'стрілочна',
        ];
        foreach ($vectors as $template => $why) {
            $response = $this->api('PUT', self::HOME, "{% extends '@engine/services/home.html.twig' %}{% block meta_generator %}" . $template . '{% endblock %}');
            self::assertSame(422, $response->getStatusCode(), $template);
            self::assertStringContainsString($why, (string) (json_decode((string) $response->getContent(), true)['error'] ?? ''), $template);
        }
        self::assertFileDoesNotExist($this->themeRoot . '/templates/' . self::HOME, 'нічого не записано');

        $home = $this->http('GET', 'https://' . $this->apex . '/');
        self::assertSame(200, $home->getStatusCode());
        $html = (string) $home->getContent();
        foreach (['APP_SECRET', 'DATABASE_URL'] as $env) {
            $value = (string) ($_SERVER[$env] ?? getenv($env));
            self::assertNotSame('', $value);
            self::assertStringNotContainsString($value, $html, $env . ' не на сторінці');
        }
    }

    public function testAllowedTemplateIsWrittenRenderedAndRemoved(): void
    {
        $template = "{% extends '@engine/services/home.html.twig' %}{% block meta_generator %}<meta name=\"ci-live\" content=\"CI-LIVE-{{ app.request.locale }}\">{% endblock %}";
        $response = $this->api('PUT', self::HOME, $template);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('CI-LIVE-uk', (string) $this->http('GET', 'https://' . $this->apex . '/')->getContent());

        self::assertSame(200, $this->api('DELETE', self::HOME)->getStatusCode());
        // на сервері кожен запит — новий процес; тут те саме ядро тримає в пам'яті вже завантажений шаблон
        self::ensureKernelShutdown();
        self::bootKernel();
        self::assertStringNotContainsString('CI-LIVE', (string) $this->http('GET', 'https://' . $this->apex . '/')->getContent());
    }

    /** Версія з історії, записана до стража (заборонені конструкції), назад не повертається. */
    public function testRevertOfUnsafeHistoryVersionIsRejected(): void
    {
        $dir = $this->themeRoot . '/history/' . str_replace('/', '__', self::HOME);
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/20261015-120000', "{{ app.request.server.get('APP_SECRET') }}");
        $response = $this->api('POST', self::HOME . '/revert', (string) json_encode(['version' => '20261015-120000']), 'application/json');
        self::assertSame(422, $response->getStatusCode());
        self::assertFileDoesNotExist($this->themeRoot . '/templates/' . self::HOME);
    }

    /**
     * Паралельні записи: поки інший процес тримає замок живої теми (як PUT із рендером сторінок), PUT чекає й пише після
     * нього; тримає довше за app.theme_lock_wait (test: 3 с) — 409 + Retry-After на PUT і DELETE, файл не змінено.
     */
    public function testConcurrentWritesAreSerialized(): void
    {
        $first = "{% extends '@engine/services/home.html.twig' %}{% block meta_generator %}<meta name=\"ci-live\" content=\"ONE\">{% endblock %}";
        $second = str_replace('ONE', 'TWO', $first);

        $holder = $this->holdLock(1.5);
        $started = microtime(true);
        $response = $this->api('PUT', self::HOME, $first);
        $waited = microtime(true) - $started;
        $this->release($holder);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertGreaterThan(1.0, $waited, 'PUT чекав, поки замок відпустять');

        $holder = $this->holdLock(8);
        try {
            $busy = $this->api('PUT', self::HOME, $second);
            self::assertSame(409, $busy->getStatusCode(), (string) $busy->getContent());
            self::assertSame('5', $busy->headers->get('Retry-After'));
            self::assertSame(409, $this->api('DELETE', self::HOME)->getStatusCode());
            $started = microtime(true);
            self::assertSame(422, $this->api('PUT', self::HOME, "{{ app.request.server.get('APP_SECRET') }}")->getStatusCode(), 'страж — до замка');
            self::assertLessThan(1.0, microtime(true) - $started, 'відхилений шаблон не чекає замка');
        } finally {
            $this->release($holder);
        }
        self::assertStringContainsString('ONE', (string) file_get_contents($this->themeRoot . '/templates/' . self::HOME), 'файл не змінено');

        self::assertSame(200, $this->api('PUT', self::HOME, $second)->getStatusCode(), 'замок вільний — пишеться');
    }

    private function api(string $method, string $path, string $body = '', string $type = 'text/plain'): Response
    {
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $request = Request::create('https://' . $this->adminHost . '/api/admin/v1/' . $this->slug . '/theme/' . $path, $method, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->bearer,
            'CONTENT_TYPE' => $type,
        ], $body);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * Окремий процес бере той самий замок (LockFactory + FlockStore у var/theme, ключ theme-live) і тримає $seconds.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function holdLock(float $seconds): array
    {
        $code = sprintf(
            'require %s; $l = (new Symfony\Component\Lock\LockFactory(new Symfony\Component\Lock\Store\FlockStore(%s)))->createLock("theme-live"); $l->acquire(true); echo "locked\n"; usleep(%d); $l->release();',
            var_export(static::getContainer()->getParameter('kernel.project_dir') . '/vendor/autoload.php', true),
            var_export($this->themeRoot, true),
            (int) ($seconds * 1_000_000),
        );
        $process = proc_open([\PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame("locked\n", fgets($pipes[1]), 'замок узято');

        return [$process, $pipes];
    }

    /** @param array{0: resource, 1: array<int, resource>} $holder */
    private function release(array $holder): void
    {
        proc_terminate($holder[0]);
        fclose($holder[1][1]);
        proc_close($holder[0]);
    }

    private function cleanTheme(): void
    {
        @unlink($this->themeRoot . '/templates/' . self::HOME);
        $history = $this->themeRoot . '/history/' . str_replace('/', '__', self::HOME);
        foreach (glob($history . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($history);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
