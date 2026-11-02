<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceItemRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Старі адреси сайту після переїзду на рушій: 301 із паку (app.legacy_redirects), 410 для
 * прибраних розділів (app.gone_prefixes) і загальна гігієна URL (app.clean_urls): замикаючий
 * слеш, зайвий query на сторінках контенту. Використовується LegacyUrlSubscriber до роутингу
 * і LegacyRedirectController (той самий сервіс, щоб правило спрацьовувало й там, де маршрут
 * збігся з чужим патерном: /services/<старий-слаг>/ ловить sv_category).
 *
 * Формат app.legacy_redirects (ключ = старий шлях без слешів з обох боків, uk або ru/…; порівняння
 * exact, зі слешем і без):
 *   старий-слаг: { city: slug|null, item: slug-послуги|null, route: назва|null, locale: uk|ru }  — як було
 *   services/heating/old-slug: /services/heating/new-slug/                                     — цільовий шлях
 *   ru/services/x: { path: /ru/services/y/ }                                                   — те саме явно
 * Ціль-шлях лишається на поточному хості (місто) — редиректи переїжджають разом із містом.
 */
final class LegacyUrls
{
    private const CLEAN_DEFAULTS = [
        'trailing_slash' => true,
        'skip_prefixes' => ['/api/', '/admin', '/account', '/_', '/connect/'],
        'strip_query' => true,
        // strip_query_paths типово будується під схему URL і префікс блогу — див. defaultStripQueryPaths()
        'keep_query' => ['utm_*', 'gclid', 'fbclid', 'yclid', 'msclkid', 'dclid', 'wbraid', 'gbraid', 'ttclid', 'x', 'src'],
        'prefix_redirects' => [],
    ];

    /** @var array<string, array<string, mixed>|string> */
    private readonly array $map;
    /** @var array<string, mixed> */
    private readonly array $clean;
    /** @var list<string> */
    private readonly array $gone;

    /**
     * @param array<string, mixed> $legacyRedirects app.legacy_redirects
     * @param array<string, mixed> $cleanUrls       app.clean_urls
     * @param list<string>         $gonePrefixes    app.gone_prefixes
     */
    public function __construct(
        private readonly ServiceItemRepository $items,
        private readonly ServiceCityRepository $cities,
        private readonly CatalogUrls $catalogUrls,
        private readonly UrlGeneratorInterface $router,
        #[Autowire('%app.legacy_redirects%')]
        array $legacyRedirects = [],
        #[Autowire('%app.clean_urls%')]
        array $cleanUrls = [],
        #[Autowire('%app.gone_prefixes%')]
        array $gonePrefixes = [],
    ) {
        $map = [];
        foreach ($legacyRedirects as $key => $rule) {
            $map[self::key((string) $key)] = $rule;
        }
        $this->map = $map;
        $clean = $cleanUrls + self::CLEAN_DEFAULTS;
        if (!isset($clean['strip_query_paths']) || (string) $clean['strip_query_paths'] === '') {
            $clean['strip_query_paths'] = $this->defaultStripQueryPaths();
        }
        $clean['prefix_redirects'] = self::validatedPrefixRedirects((array) $clean['prefix_redirects']);
        $this->clean = $clean;
        $this->gone = array_values(array_map(static fn ($p) => '/' . trim((string) $p, '/'), $gonePrefixes));
    }

    /**
     * Типовий регекс сторінок, де зайвий query зрізається: flat/nested — /services/, /districts/, блог;
     * root і slug — також будь-який /<категорія>/[<послуга>/] чи /<послуга>/, крім фіксованих сторінок
     * (CatalogUrls::RESERVED_SEGMENTS), бо каталог там живе від кореня, а ?Itemid= Joomla стоїть саме на цих адресах.
     * Пак може задати свій.
     */
    public function defaultStripQueryPaths(): string
    {
        $blog = preg_quote($this->catalogUrls->blogPrefix(), '#');
        if (!$this->catalogUrls->categoriesAtRoot()) {
            return '#^/(ru/)?(services|districts|' . $blog . ')(/|$)#';
        }
        $reserved = implode('|', array_map(static fn (string $s) => preg_quote($s, '#'), array_diff(CatalogUrls::RESERVED_SEGMENTS, ['services', 'districts', 'blog', 'news'])));

        return '#^/(ru/)?(services|districts|' . $blog . '|(?!(' . $reserved . ')(/|$))[a-z0-9-]+)(/|$)#';
    }

    /**
     * Перевірка app.clean_urls.prefix_redirects при старті: from і to — шляхи з '/', from не корінь, to не починається
     * з from (сам на себе: /uk/ → /uk/x/), і жоден ланцюжок правил не замикається (/a/ → /b/ → /a/). Помилковий конфіг
     * раніше давав нескінченну рекурсію в legacyTarget і 500 на кожному запиті з таким префіксом.
     *
     * @param array<string, mixed> $rules
     *
     * @return array<string, string>
     */
    public static function validatedPrefixRedirects(array $rules): array
    {
        $out = [];
        foreach ($rules as $from => $to) {
            $from = (string) $from;
            $to = (string) $to;
            if ($from === '' || $from === '/' || !str_starts_with($from, '/')) {
                throw new \InvalidArgumentException(sprintf('app.clean_urls.prefix_redirects: ключ «%s» має бути шляхом з «/» і не коренем (наприклад, /uk/).', $from));
            }
            if ($to === '' || !str_starts_with($to, '/')) {
                throw new \InvalidArgumentException(sprintf('app.clean_urls.prefix_redirects: ціль для «%s» має починатися з «/» (отримано «%s»).', $from, $to));
            }
            if (str_starts_with($to, $from)) {
                throw new \InvalidArgumentException(sprintf('app.clean_urls.prefix_redirects: ціль «%s» починається з «%s» — редірект сам на себе.', $to, $from));
            }
            $out[$from] = $to;
        }
        // цикли: йдемо по ланцюжку правил від кожної цілі; довше за кількість правил = замкнулося
        foreach ($out as $from => $to) {
            $path = $to;
            for ($i = 0; $i <= \count($out); ++$i) {
                $next = null;
                foreach ($out as $f => $t) {
                    if (str_starts_with($path, $f)) {
                        $next = $t . substr($path, \strlen($f));
                        break;
                    }
                }
                if ($next === null) {
                    continue 2;
                }
                $path = $next;
            }

            throw new \InvalidArgumentException(sprintf('app.clean_urls.prefix_redirects: правила замикаються в цикл, починаючи з «%s» → «%s».', $from, $to));
        }

        return $out;
    }

    /** Нормалізований ключ мапи: без слешів по краях, без url-кодування, у нижньому регістрі. */
    public static function key(string $path): string
    {
        return mb_strtolower(trim(rawurldecode($path), '/'));
    }

    /** Шлях прибраного розділу (дорвеї, старі «новини») — 410 Gone. */
    public function isGone(string $path): bool
    {
        foreach ($this->gone as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Абсолютна ціль 301 для старого шляху (без query — його додає queryFor()) або null, якщо правила нема.
     * Порядок: мапа паку → префіксні правила app.clean_urls.prefix_redirects ({'/uk/': '/'}: мовний префікс Joomla
     * для мови за замовчуванням). Після префікса новий шлях один раз звіряється з мапою і дістає замикаючий слеш,
     * щоб /uk/foo → /foo/ (чи ціль з мапи) був одним 301, а не ланцюжком. Без рекурсії: правило застосовується
     * один раз, цикли в конфігу відсікає конструктор.
     */
    public function legacyTarget(Request $request, string $path): ?string
    {
        $rule = $this->map[self::key($path)] ?? null;
        if ($rule !== null) {
            return $this->ruleTarget($request, $rule);
        }
        foreach ($this->clean['prefix_redirects'] as $from => $to) {
            if (!str_starts_with($path, $from)) {
                continue;
            }
            $newPath = '/' . ltrim($to . substr($path, \strlen($from)), '/');
            $mapped = $this->map[self::key($newPath)] ?? null;
            if ($mapped !== null) {
                return $this->ruleTarget($request, $mapped);
            }

            return $request->getSchemeAndHttpHost() . ($this->trailingSlashTarget($newPath) ?? $newPath);
        }

        return null;
    }

    /**
     * Query, який має поїхати разом із 301 на шлях $path: на сторінках за strip_query_paths — лише трекінг
     * (keep_query), інакше — як був. Повертає '' або '?…'. Так старий шлях з ?Itemid= і слеш/префікс
     * зводяться в один 301 замість ланцюжка.
     */
    public function queryFor(Request $request, string $path): string
    {
        $all = $request->query->all();
        if ($all === []) {
            return '';
        }
        $kept = $this->keptQuery($request, $path) ?? $all;

        return $kept !== [] ? '?' . http_build_query($kept) : '';
    }

    /** @param array<string, mixed> $rule */
    private function ruleTarget(Request $request, array|string $rule): ?string
    {
        $host = $request->getSchemeAndHttpHost();

        $literal = \is_string($rule) ? $rule : (string) ($rule['path'] ?? '');
        if ($literal !== '') {
            // абсолютна адреса (інший домен) — як є; шлях — на поточному хості
            return preg_match('#^https?://#i', $literal) ? $literal : $host . '/' . ltrim(trim($literal), '/');
        }

        $locale = ($rule['locale'] ?? 'uk') === 'ru' ? 'ru' : 'uk';
        if (!empty($rule['route'])) {
            return $this->router->generate((string) $rule['route'], ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        /** @var ServiceCity|null $current */
        $current = $request->attributes->get('service_city');
        if ($current === null) {
            return null;
        }
        $site = $current->getSite();
        $target = isset($rule['city']) && $rule['city'] !== null
            ? $this->cities->findActiveBySiteAndSlug($site, (string) $rule['city'])
            : null;
        // місто ще в розробці - його сторінки під noindex, тож 301 на нього втратив би сигнал
        if ($target === null || $target->isNoindex()) {
            $target = $current;
        }

        $targetPath = $locale === 'ru' ? '/ru/' : '/';
        if (!empty($rule['item'])) {
            $item = $this->items->findOneActiveBySiteAndSlug($site, (string) $rule['item'], 'uk');
            if ($item !== null) {
                $targetPath = $this->router->generate('sv_service', $this->catalogUrls->itemParams($item, $locale) + ['_locale' => $locale]);
            }
        }
        $targetHost = $target->getId() === $current->getId() ? $host : $request->getScheme() . '://' . $target->getHost();

        return $targetHost . $targetPath;
    }

    /** Шлях, якому бракує замикаючого слеша (сторінки без розширення поза службовими префіксами). */
    public function trailingSlashTarget(string $path): ?string
    {
        if (!$this->clean['trailing_slash'] || $path === '/' || str_ends_with($path, '/')) {
            return null;
        }
        if (preg_match('#/[^/]*\.[a-z0-9]{1,5}$#i', $path)) {
            return null; // sitemap.xml, robots.txt, файли
        }
        foreach ((array) $this->clean['skip_prefixes'] as $prefix) {
            if (str_starts_with($path, (string) $prefix)) {
                return null;
            }
        }

        return $path . '/';
    }

    /**
     * Чистий URL для сторінки контенту із зайвими параметрами (?task=no з Joomla, ?Itemid=…):
     * трекінг (utm_*, gclid…) лишається, решта зрізається. null = нічого міняти.
     */
    public function cleanQueryTarget(Request $request, ?string $path = null): ?string
    {
        $path ??= $request->getPathInfo();
        $keep = $this->keptQuery($request, $path);
        if ($keep === null || \count($keep) === $request->query->count()) {
            return null;
        }

        return $request->getSchemeAndHttpHost() . $path . ($keep !== [] ? '?' . http_build_query($keep) : '');
    }

    /**
     * Параметри, що лишаються на сторінці $path за правилами strip_query; null = сторінка не під правилом
     * (або зрізання вимкнене, або query порожній) — query лишається як був.
     *
     * @return array<string, mixed>|null
     */
    private function keptQuery(Request $request, string $path): ?array
    {
        if (!$this->clean['strip_query'] || $request->query->count() === 0) {
            return null;
        }
        if (!preg_match((string) $this->clean['strip_query_paths'], $path)) {
            return null;
        }
        $keep = [];
        foreach ($request->query->all() as $name => $value) {
            if ($this->keepsParam((string) $name)) {
                $keep[$name] = $value;
            }
        }

        return $keep;
    }

    private function keepsParam(string $name): bool
    {
        foreach ((array) $this->clean['keep_query'] as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $name || (str_ends_with($pattern, '*') && str_starts_with($name, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

}
