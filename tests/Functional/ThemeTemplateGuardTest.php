<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\ThemeTemplateGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Страж живого шаблону (ThemeTemplateGuard + LiveThemePolicy) на справжньому Environment рушія (функції сайту, Liip,
 * FrameworkBundle — render/controller теж зареєстровані): кожен відомий обхід — відмова до запису; те, що рушій і паки
 * вживають, — дозволено. БД не потрібна, але потрібне ядро, тому тут, а не в tests/Unit.
 */
final class ThemeTemplateGuardTest extends KernelTestCase
{
    private function guard(): ThemeTemplateGuard
    {
        self::bootKernel();

        return static::getContainer()->get(ThemeTemplateGuard::class);
    }

    /** @return iterable<string, array{string}> */
    public static function denied(): iterable
    {
        // M1: секрети оточення через Request::server і обхідні шляхи до app.request
        yield 'app.request.server' => ["{{ app.request.server.get('APP_SECRET') }}"];
        yield '_context' => ['{{ _context.app.request.server.all|json_encode }}'];
        yield 'attribute()' => ["{{ attribute(app.request, 'ser' ~ 'ver').all|json_encode }}"];
        yield 'attribute() не на app' => ["{{ attribute(site, 'settings') }}"];
        yield 'set r = app' => ["{% set r = app %}{{ r.request.server.get('DATABASE_URL') }}"];
        yield 'set r = app.request' => ['{% set r = app.request %}{{ r.server.all|json_encode }}'];
        yield 'ternary app' => ['{{ (true ? app : null).request.server.all|json_encode }}'];
        yield 'array [app]' => ['{{ [app][0].request.server.all|json_encode }}'];
        yield 'hash {a: app}' => ['{{ {a: app}.a.request.server.all|json_encode }}'];
        yield 'app.request ?: x' => ["{% set r = app.request ?: 'x' %}{{ r.server.all|json_encode }}"];
        yield 'true ? app.request : 0' => ['{% set r = true ? app.request : 0 %}'];
        yield 'app.request ?? x' => ["{% set r = app.request ?? 'x' %}"];
        yield 'if app.request → headers' => ['{% if app.request %}{{ app.request.headers.all|json_encode }}{% endif %}'];
        yield 'app.request.attributes' => ["{{ app.request.attributes.get('_controller') }}"];
        yield 'app.request.cookies' => ['{{ app.request.cookies.all|json_encode }}'];
        yield 'app.session' => ['{{ app.session.all|json_encode }}'];
        yield 'app.token' => ['{{ app.token.user.password }}'];
        yield 'app.user.password' => ['{{ app.user.password }}'];
        yield 'app.user.roles' => ['{{ app.user.roles|join }}'];
        yield 'app.environment' => ['{{ app.environment }}{{ app.debug }}'];
        yield 'app як аргумент' => ['{{ app.request.query.get(app) }}'];
        yield 'app.request як аргумент макроса' => ['{% macro m(r) %}{{ r }}{% endmacro %}{% import _self as s %}{{ s.m(app.request) }}'];
        yield 'loop.parent.app' => ['{% for x in [1] %}{{ loop.parent.app.request.server.all|json_encode }}{% endfor %}'];
        yield "loop.parent['app']" => ["{% for x in [1] %}{{ loop.parent['app'].request.server.all|json_encode }}{% endfor %}"];
        yield "loop['parent']['app'].request" => ["{% for x in [1] %}{% set a = loop['parent']['app'] %}{{ a.request.server.all|json_encode }}{% endfor %}"];
        // рев'ю PR #8: деструктуризація (getAttribute ANY_CALL з неперевіреним ключем) і весь контекст через loop/_parent
        yield 'деструктуризація app' => ["{% for i in [1] %}{% do {request: r} = loop['parent']['app'] %}{% do {server: s} = r %}{{ s.get('APP_SECRET') }}{% endfor %}"];
        yield 'деструктуризація token' => ["{% for i in [1] %}{% do {token: t} = loop['parent']['app'] %}{% endfor %}"];
        yield 'деструктуризація в set' => ["{% set x = ({request: r} = site) %}"];
        yield 'деструктуризація в друці' => ['{{ {name: n} = site }}'];
        yield 'деструктуризація послідовності' => ['{% do [a, b] = [1, 2] %}'];
        yield 'has some(callable)' => ["{{ ['abc'] has some('is_string') ? 1 : 0 }}"];
        yield 'has every(змінна)' => ["{% set f = 'is_string' %}{{ ['abc'] has every(f) ? 1 : 0 }}"];
        yield 'присвоєння у виразі' =>['{% set x = (y = 5) %}'];
        yield "loop['parent']" => ["{% for i in [1] %}{% set c = loop['parent'] %}{% endfor %}"];
        yield 'loop[вираз]' => ["{% for i in [1] %}{% set c = loop['par' ~ 'ent'] %}{% endfor %}"];
        yield 'loop як значення' => ["{% for i in [1] %}{% set l = loop %}{{ l['parent']['app'] }}{% endfor %}"];
        yield '_parent' => ["{% for i in [1] %}{{ _parent['app'] is defined }}{% endfor %}"];
        yield 'for in _context' =>['{% for k, v in _context %}{{ k }}{% endfor %}'];
        yield '_self' => ['{{ _self }}'];
        yield 'include with {a: app}' => ["{% include 'services/partials/_faq.html.twig' with {a: app} %}"];
        // M2: sub-request без firewall і читання будь-якого шаблону
        yield 'render(controller())' => ["{{ render(controller('App\\\\Controller\\\\Admin\\\\DashboardController::index')) }}"];
        yield 'controller()' => ["{% set c = controller('App\\\\Controller\\\\Api\\\\Admin\\\\MeController::me') %}"];
        yield 'render(path)' => ["{{ render(path('sv_home')) }}"];
        yield 'render_esi' => ["{{ render_esi(path('sv_home')) }}"];
        yield 'include_only' => ["{{ include_only(app.request.query.get('t')) }}"];
        yield 'include адмінки' => ["{% include 'admin/dashboard.html.twig' %}"];
        yield 'include() листа' => ["{{ include('emails/lead.html.twig') }}"];
        yield 'include виразом' => ["{% include app.request.query.get('t') %}"];
        yield 'include масивом' => ["{% include ['services/home.html.twig', 'admin/x.html.twig'] %}"];
        yield 'extends бандла' => ["{% extends '@EasyAdmin/page/content.html.twig' %}"];
        yield 'extends ..' => ["{% extends '@engine/services/../admin/x.html.twig' %}"];
        yield 'embed адмінки' => ["{% embed 'admin/x.html.twig' %}{% endembed %}"];
        yield 'import листа' => ["{% import 'emails/lead.html.twig' as m %}"];
        yield 'use' => ["{% use 'admin/x.html.twig' %}"];
        yield 'block(name, expr)' => ["{{ block('body', app.request.query.get('t')) }}"];
        yield 'source()' => ["{{ source('services/home.html.twig') }}"];
        yield 'dump()' => ['{{ dump() }}'];
        // callable, сталі, тести
        yield '|map(system)' => ["{{ ['id']|map('system')|join }}"];
        yield '|filter(system)' => ["{{ ['id']|filter('system')|join }}"];
        yield '|sort(system)' => ["{{ ['id']|sort('system')|join }}"];
        yield '|reduce(system)' => ["{{ ['id']|reduce('system') }}"];
        yield '|map(v => v.server)' => ['{{ [1]|map(v => v.server.all)|join }}'];
        yield 'constant(клас)' => ["{{ constant('App\\\\Kernel::class') }}"];
        yield 'constant(PHP_OS)' => ["{{ constant('PHP_OS') }}"];
        yield 'is constant' => ["{{ 1 is constant('PHP_OS') }}"];
        yield 'невідомий фільтр' => ["{{ 'x'|data_uri }}"];
        // властивості й методи поза списком
        yield 'google_oauth секрет' => ['{{ google_oauth.clientSecret }}'];
        yield 'site.settings' => ['{{ site.settings|length }}'];
        yield '.token' => ['{{ gbp_reviews.token }}'];
        yield 'сетер' => ["{% do site.setName('x') %}"];
    }

    #[DataProvider('denied')]
    public function testDenied(string $template): void
    {
        self::assertNotSame([], $this->guard()->check('services/partials/_ci.html.twig', $template), $template);
    }

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'extends @engine + parent()' => ["{% extends '@engine/services/home.html.twig' %}{% block title %}{{ parent() }} | {{ app.request.locale }}{% endblock %}"];
        yield 'extends рушія без @engine' => ["{% extends 'services/base.html.twig' %}"];
        yield 'app.request.query.get' => ["{{ app.request.query.get('src') }}"];
        yield 'app.request.schemeAndHttpHost' => ['{{ app.request.schemeAndHttpHost }}{{ app.request.pathInfo }}{{ app.request.host }}'];
        yield 'app.user' => ['{{ app.user ? app.user.email }}{{ app.user.displayName ?? "" }}'];
        yield 'bool app.request' => ["{% if app.request %}x{% endif %}{{ app.request is not null and app.request.pathInfo starts with '/ru/' ? 1 : 0 }}"];
        yield 'app.flashes, current_route' => ['{% for m in app.flashes %}{{ m }}{% endfor %}{{ app.current_route }}'];
        yield 'масив за ключем' => ["{{ gbp_reviews['token']|default('') }}"];
        yield 'import _self' => ['{% import _self as m %}{% macro x(a) %}{{ a }}{% endmacro %}{{ m.x(1) }}'];
        yield 'import partial' => ["{% import 'services/partials/_schema.html.twig' as schema %}"];
        yield 'include partial' => ["{% include 'services/partials/_faq.html.twig' with {faq: []} only %}{{ include('@engine/services/partials/_messengers.html.twig') }}"];
        yield 'стрілочні' => ['{{ [1, 2]|map(v => v * 2)|filter(v => v > 2)|sort((a, b) => a <=> b)|join(",") }}'];
        yield 'has some зі стрілкою' => ['{{ [1, 2] has some(v => v > 1) and [1, 2] has every(v => v > 0) ? 1 : 0 }}'];
        yield 'constant(JSON_*)' => ["{{ {a: 1}|json_encode(constant('JSON_UNESCAPED_UNICODE') b-or constant('JSON_UNESCAPED_SLASHES'))|raw }}"];
        yield 'функції рушія' => ["{{ path('sv_home') }}{{ csrf_token('lead') }}{{ asset('x.css') }}{{ theme.get('logo') }}{{ site_brand(site, locale) }}"];
        yield 'loop' => ["{% for x in [1] %}{{ loop.index }}{{ loop.first ? 'a' }}{{ loop.length }}{{ loop['last'] ? 'z' }}{% endfor %}"];
    }

    #[DataProvider('allowed')]
    public function testAllowed(string $template): void
    {
        self::assertSame([], $this->guard()->check('services/partials/_ci.html.twig', $template), $template);
    }

    /** Кожен шаблон рушія, який можна перекрити, проходить стража як є — списки не відстають від рушія непомітно. */
    public function testEveryOverridableEngineTemplatePasses(): void
    {
        $guard = $this->guard();
        $root = static::getContainer()->getParameter('kernel.project_dir') . '/templates/';
        $checked = 0;
        foreach (['services', 'partials'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($file->getPathname(), \strlen($root)));
                self::assertSame([], $guard->check($rel, (string) file_get_contents($file->getPathname())), $rel);
                ++$checked;
            }
        }
        self::assertGreaterThan(20, $checked);
    }

    public function testAllowedReferences(): void
    {
        self::assertTrue(ThemeTemplateGuard::isAllowedReference('services/home.html.twig'));
        self::assertTrue(ThemeTemplateGuard::isAllowedReference('@engine/partials/_img.html.twig'));
        self::assertFalse(ThemeTemplateGuard::isAllowedReference('admin/dashboard.html.twig'));
        self::assertFalse(ThemeTemplateGuard::isAllowedReference('@engine/emails/lead.html.twig'));
        self::assertFalse(ThemeTemplateGuard::isAllowedReference('services/../admin/x.html.twig'));
        self::assertFalse(ThemeTemplateGuard::isAllowedReference('@EasyAdmin/layout.html.twig'));
    }
}
