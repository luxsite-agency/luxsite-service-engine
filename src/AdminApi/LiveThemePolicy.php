<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

/**
 * Явний allowlist для живих шаблонів (порт із shop-engine PR #108 після рев'ю M1–M2, 22.10.2026): що саме живий шаблон
 * має право вживати. Усе, чого тут нема, ThemeTemplateGuard відхиляє до запису — невідомий тег, фільтр, функція, тест,
 * метод чи властивість означають відмову, а не «мабуть, безпечно».
 *
 * Чому не Twig SandboxExtension: per-source режим (SourcePolicyInterface) deprecated з Twig 3.27 без заміни, а окреме
 * Sandbox-оточення не вміє «живий services/home.html.twig перекриває ім'я рушія і робить extends @engine/…». Тому ті самі
 * правила перевіряються статично по AST — до запису, не під час рендера; attribute() заборонено, тож імена властивостей
 * завжди відомі на етапі парсингу.
 *
 * База списків — те, що рушій уживає в templates/services/** і templates/partials/** (тест
 * ThemeTemplateGuardTest::testEveryOverridableEngineTemplatePasses стежить, щоб списки не відстали від шаблонів рушія),
 * плюс безпечні імена з шаблонів паків. Додати ім'я сюди — свідома дія з рев'ю, не побічний ефект.
 */
final class LiveThemePolicy
{
    /** Теги. Поза списком: sandbox, flush, guard, deprecated, cache, trans, form_theme, stopwatch, dump. */
    public const array TAGS = ['apply', 'autoescape', 'block', 'do', 'embed', 'extends', 'for', 'from', 'if', 'import', 'include', 'macro', 'set', 'use', 'verbatim', 'with', 'types'];

    /** Фільтри. Поза списком: invoke (виклик callable), data_uri, serialize, yaml_*, sanitize_html, ea_*, file_*, abbr_*, format_args*. */
    public const array FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'date', 'date_modify', 'default', 'e', 'escape', 'filter', 'find', 'first', 'format',
        'imagine_filter', 'join', 'json_encode', 'keys', 'last', 'length', 'lower', 'map', 'merge', 'nl2br', 'number_format', 'raw',
        'reduce', 'replace', 'reverse', 'round', 'slice', 'sort', 'spaceless', 'split', 'striptags', 'title', 'trans', 'trim', 'upper',
        'url_encode',
    ];

    /**
     * Функції. Поза списком: attribute, render, render_*, controller, fragment_uri, include_only, source, dump,
     * template_from_string, expression, form_*, logout_*, impersonation_*, ea*, admin_title. constant — лише літерал JSON_*
     * (ThemeTemplateGuard::CONSTANT_RE); include — лише літерал services/…, partials/… чи @engine/….
     */
    public const array FUNCTIONS = [
        'absolute_url', 'asset', 'block', 'constant', 'csrf_token', 'cycle', 'date', 'html_classes', 'include', 'max', 'min', 'parent',
        'path', 'random', 'range', 'relative_path', 'url',
        // рушій сервісних сайтів
        'calculator_available', 'city_in', 'city_switcher', 'custom_fields', 'footer_catalog', 'item_text', 'ov', 'page_meta', 'reviews_title_city',
        'service_path', 'site_brand', 'site_has_blog', 'site_has_calculator', 'site_lead_js', 'site_tags',
        // сторінки app.pages, мови розгортання й бренду, контентний сайт (stage/satellites); sv_layout — лише ім'я шаблону
        'footer_pages', 'sv_categories', 'sv_category_posts', 'sv_date', 'sv_default_locale', 'sv_layout', 'sv_locale', 'sv_locale_code', 'sv_locales', 'sv_thumbs',
        'sv_path_locale',
    ];

    /** Тести. Поза списком: constant. */
    public const array TESTS = ['defined', 'divisible by', 'empty', 'even', 'false', 'iterable', 'mapping', 'none', 'null', 'odd', 'same as', 'sequence', 'true'];

    /**
     * Ланцюжки від глобального app — точні (app.request.locale, не app.request): app.request сам по собі дав би server/
     * headers/cookies/session (секрети оточення в $_SERVER — DATABASE_URL, APP_SECRET, ключі), app.session і app.token —
     * сесію й автентифікацію. Далі app.user перевіряють ATTRIBUTES (password, roles там нема).
     */
    public const array APP_CHAINS = [
        'request.locale', 'request.pathInfo', 'request.uri', 'request.requestUri', 'request.host', 'request.schemeAndHttpHost',
        'request.query.all', 'request.query.get', 'request.query.has',
        'flashes', 'user', 'current_route', 'current_route_parameters', 'locale',
    ];

    /**
     * Змінні контексту, яких живому шаблону не дати: _context і _parent (його ставлять for/with) — увесь контекст разом з app;
     * _self — лише в {% import _self %}.
     */
    public const array DENIED_VARIABLES = ['_context', '_parent', '_self'];

    /**
     * Що можна брати з loop — лише точний ключ (loop.index, loop['first']); саме loop як значення і loop.parent (увесь
     * контекст разом з app) — відмова.
     */
    public const array LOOP_KEYS = ['index', 'index0', 'revindex', 'revindex0', 'first', 'last', 'length'];

    /**
     * Імена властивостей і методів (x.name, x.name()) — те, що рушій і паки вживають у шаблонах сайту. Навмисно нема:
     * request, session, token, server, headers, cookies, environment, debug, user, parent (loop.parent — весь контекст разом
     * з app), password, roles, settings і жодних сетерів, add…/remove… — це поверхня зміни даних або секретів.
     * Масиви з таким ключем — x['token'], x['parent'] (ARRAY_CALL на об'єкті без ArrayAccess методів не викликає).
     */
    public const array ATTRIBUTES = [
        'a', 'aboutUk', 'address', 'apexDomain', 'avatarUrl', 'avg', 'base', 'beforeAlt', 'beforeName', 'bingVerification',
        'calcType', 'category', 'city', 'clarityId', 'colors', 'configured', 'consentBanner', 'consultation_cost', 'count', 'cssVars',
        'dirt_level', 'displayName', 'districts', 'email', 'experience', 'extraValue', 'filter', 'first', 'fleet', 'founder', 'get',
        'googleVerification', 'gtagIds', 'gtagLoaderId', 'gtmId', 'hasMessengers', 'hasPair', 'heroImagePath', 'host', 'hours',
        'htmlBodyEnd', 'htmlBodyStart', 'htmlHead', 'icon', 'id', 'imageAlt', 'imageName', 'includes_site', 'index', 'index0',
        'inlineCss', 'isApex', 'isDefault', 'isPrimary', 'item', 'items', 'key', 'kind', 'l', 'label', 'landing', 'last', 'lastmod',
        'legal_name', 'length', 'liveCssUrl', 'loc', 'location', 'mapEmbedUrl', 'mapLinkUrl', 'maxQty', 'mega', 'minPrice',
        'multiplier', 'name', 'noindex', 'object_type', 'opening_hours', 'option', 'perUnit', 'phone', 'pixelId', 'pixelJs',
        'position', 'price', 'pricePerSqm', 'pricePrefix', 'primary', 'publishedAt', 'q', 'reviewedAt', 'revindex', 'revindex0',
        'rows', 'ru', 'runtime', 'runtimeJs', 'schemaType', 'shortName', 'skip', 'slug', 'slugUk', 'source', 'stars', 'tagKind',
        'telegram', 'uk', 'updatedAt', 'url', 'v', 'value', 'viber', 'whatsapp',
        // сторінки app.pages, мови й контентний сайт (stage/satellites); батька сторінки — змінною parent_page (parent заборонено)
        'aboutFor', 'active', 'author', 'brandName', 'existsIn', 'images', 'path', 'urls',
        // теги статей контентного сайту (24.10.2026): лише читання активних тегів мовою сторінки
        'tagsIn',
        // RSS контентного сайту в <head> (25.10.2026)
        'isContent',
        // дата змістовного оновлення статті (25.10.2026): dateModified і «Updated» замість технічного updatedAt
        'modifiedAt', 'isUpdated',
    ];

    /** Короткий опис правил для list().rules, 422 і описів MCP. */
    public static function summary(): string
    {
        return 'Лише дозволені теги, фільтри, функції й властивості (allowlist, LiveThemePolicy): без app.request/app.session (лише app.request.locale|pathInfo|uri|host|schemeAndHttpHost|query.get, app.user, app.flashes, app.current_route), без _context, attribute(), render(), controller(), include_only(), source(), dump(), include з виразом, constant() поза JSON_*, рядків-callable у filter/map/sort. Ключі масивів — x[\'key\'].';
    }
}
