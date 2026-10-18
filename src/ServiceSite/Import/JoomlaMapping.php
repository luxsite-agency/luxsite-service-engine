<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Import;

use Symfony\Component\Yaml\Yaml;

/**
 * Мапінг паку для app:joomla:import (seo/joomla-mapping.yaml у паку сайту): усе, що залежить від
 * конкретного сайту — id міст і категорій, слаги, шляхи до FAQ-JSON, правила цін і текстів.
 * Рушій знає лише конвенції Seblod (таблиці cck_store_form_<тип>, поля <тип>_<назва>_<мова>).
 * Шляхи у файлі — відносно теки мапінгу.
 */
final class JoomlaMapping
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly string $dir,
        private readonly array $data,
    ) {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException(sprintf('Мапінг не знайдено: %s', $file));
        }
        $raw = Yaml::parseFile($file);
        $data = \is_array($raw) ? ($raw['joomla_import'] ?? null) : null;
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('Мапінг: очікується кореневий ключ joomla_import:');
        }
        foreach (['site', 'cities', 'categories'] as $required) {
            if (empty($data[$required]) || !\is_array($data[$required])) {
                throw new \InvalidArgumentException(sprintf('Мапінг: бракує розділу joomla_import.%s', $required));
            }
        }
        foreach (['slug', 'name', 'apex_domain'] as $k) {
            if (empty($data['site'][$k])) {
                throw new \InvalidArgumentException(sprintf('Мапінг: бракує site.%s', $k));
            }
        }
        foreach ($data['cities'] as $i => $city) {
            // legacy_id (запис типу domains) необов'язковий: сайт без Seblod-типу domains описує
            // місто прямо в мапінгу (name_*, phone, email…) — див. cities()
            foreach (['slug', 'host'] as $k) {
                if (!isset($city[$k]) || $city[$k] === '') {
                    throw new \InvalidArgumentException(sprintf('Мапінг: cities[%s] без %s', $i, $k));
                }
            }
        }
        foreach ($data['categories'] as $id => $cat) {
            if (empty($cat['slug_uk'])) {
                throw new \InvalidArgumentException(sprintf('Мапінг: categories[%s] без slug_uk', $id));
            }
        }
        // назви схеми, префікса й таблиць ідуть у SQL як є (sprintf, без лапок) — лише [a-z0-9_], і перевіряємо тут,
        // при завантаженні, а не коли імпорт уже дійшов до відповідного кроку
        self::ident('source.schema', $data['source']['schema'] ?? null);
        self::ident('source.prefix', $data['source']['prefix'] ?? null);
        self::ident('reviews.table', $data['reviews']['table'] ?? null);
        self::ident('team.table', $data['team']['table'] ?? null);
        self::ident('category_source.type', $data['category_source']['type'] ?? null);
        self::ident('posts.type', $data['posts']['type'] ?? null);
        foreach ((array) ($data['items'] ?? []) as $i => $t) {
            self::ident(sprintf('items[%s].type', $i), \is_array($t) ? ($t['type'] ?? null) : null);
        }

        return new self(\dirname(realpath($file) ?: $file), $data);
    }

    /**
     * Ідентифікатор для SQL (схема, префікс, таблиця, Seblod-тип): лише [a-z0-9_]. null/порожнє = не задано, ок
     * (береться дефолт); інакше виняток із назвою ключа мапінгу.
     */
    public static function ident(string $what, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!\is_string($value) && !\is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Мапінг: %s — рядок [a-z0-9_].', $what));
        }
        $value = (string) $value;
        if (!preg_match('/^[a-z0-9_]+$/', $value)) {
            throw new \InvalidArgumentException(sprintf('Мапінг: %s — лише [a-z0-9_], отримано «%s».', $what, $value));
        }

        return $value;
    }

    public function sourceSchema(): string
    {
        return (string) ($this->data['source']['schema'] ?? 'joomla_src');
    }

    public function tablePrefix(): string
    {
        return (string) ($this->data['source']['prefix'] ?? 'aim_');
    }

    /** @return array{slug: string, name: string, apex_domain: string, schema_type: string} */
    public function site(): array
    {
        $s = $this->data['site'];

        return [
            'slug' => (string) $s['slug'],
            'name' => (string) $s['name'],
            'apex_domain' => (string) $s['apex_domain'],
            'schema_type' => (string) ($s['schema_type'] ?? 'LocalBusiness'),
        ];
    }

    /**
     * Міста: legacy_id = id запису типу domains (aim_content), site_id = id у cck_core_sites
     * (на нього посилаються area_city, apl_prices.city), apex = головне місто бренду.
     * Без legacy_id місто береться лише з мапінгу (fields: name_nom_uk, name_gen_uk, name_dat_uk, name_loc_uk,
     * те саме _ru, phone, email, hours, address_uk/_ru, viber, telegram, whatsapp) — сайт без типу domains, одне місто-апекс.
     *
     * @return list<array{legacy_id: ?int, site_id: ?int, slug: string, host: string, apex: bool, fields: array<string, string>}>
     */
    public function cities(): array
    {
        $out = [];
        foreach ($this->data['cities'] as $c) {
            $fields = [];
            foreach (['name_nom_uk', 'name_gen_uk', 'name_dat_uk', 'name_loc_uk', 'name_nom_ru', 'name_gen_ru', 'name_dat_ru', 'name_loc_ru', 'phone', 'email', 'hours', 'address_uk', 'address_ru', 'viber', 'telegram', 'whatsapp'] as $k) {
                if (isset($c[$k]) && \is_scalar($c[$k]) && trim((string) $c[$k]) !== '') {
                    $fields[$k] = trim((string) $c[$k]);
                }
            }
            $out[] = [
                'legacy_id' => isset($c['legacy_id']) && $c['legacy_id'] !== '' ? (int) $c['legacy_id'] : null,
                'site_id' => isset($c['site_id']) ? (int) $c['site_id'] : null,
                'slug' => (string) $c['slug'],
                'host' => strtolower((string) $c['host']),
                'apex' => (bool) ($c['apex'] ?? false),
                'fields' => $fields,
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> id категорії Joomla → slug_uk, slug_ru, title_*, meta_* */
    public function categories(): array
    {
        $out = [];
        foreach ($this->data['categories'] as $id => $cat) {
            $out[(int) $id] = $cat;
        }

        return $out;
    }

    /** Які Seblod-типи імпортувати (ліди — лише з опцією --with-leads). */
    public function imports(string $type): bool
    {
        $types = $this->data['types'] ?? null;
        if (!\is_array($types)) {
            return true;
        }

        return \in_array($type, $types, true);
    }

    /** Абсолютний шлях до FAQ-JSON (area|service) або null. */
    public function faqFile(string $kind): ?string
    {
        $rel = $this->data['faq'][$kind] ?? null;
        if (!\is_string($rel) || $rel === '') {
            return null;
        }
        $path = str_starts_with($rel, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $rel) ? $rel : $this->dir . '/' . $rel;
        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('FAQ-файл %s не знайдено: %s', $kind, $path));
        }

        return $path;
    }

    /**
     * Призначення сторінки ipage → legacy id: about → ServiceSite.about*, privacy → ServiceSite.privacy*,
     * home → тексти й SEO головної (ServiceCity апексу). Вкладений pages.categories — див. pageCategories().
     *
     * @return array<string, int>
     */
    public function pages(): array
    {
        $out = [];
        foreach ((array) ($this->data['pages'] ?? []) as $k => $id) {
            if (\is_scalar($id) && $id !== '' && $k !== 'home_text') {
                $out[(string) $k] = (int) $id;
            }
        }

        return $out;
    }

    /** Ціни: як бути з рядками, чия послуга не імпортована (keep = без прив'язки до послуги, skip). */
    public function unlinkedPrices(): string
    {
        return (string) ($this->data['prices']['unlinked'] ?? 'keep');
    }

    /** @return array<string, array{uk: string, ru: string}> тексти для ціни без числа (free, agreement) */
    public function priceTexts(): array
    {
        $def = [
            'free' => ['uk' => 'Безкоштовно', 'ru' => 'Бесплатно'],
            'agreement' => ['uk' => 'За домовленістю', 'ru' => 'По договорённости'],
        ];
        foreach ((array) ($this->data['prices']['texts'] ?? []) as $k => $v) {
            $def[$k] = ['uk' => (string) ($v['uk'] ?? $def[$k]['uk'] ?? ''), 'ru' => (string) ($v['ru'] ?? $def[$k]['ru'] ?? '')];
        }

        return $def;
    }

    /** @return array{from: string, until: string} префікси ціни (pref_price 2/1) */
    public function pricePrefixes(): array
    {
        $p = (array) ($this->data['prices']['prefix'] ?? []);

        return ['from' => (string) ($p['from'] ?? 'від'), 'until' => (string) ($p['until'] ?? 'до')];
    }

    /** @return list<string> хости, абсолютні посилання на які стають відносними (href="https://host/…" → "/…") */
    public function linkHosts(): array
    {
        $hosts = (array) ($this->data['texts']['link_hosts'] ?? []);
        if ($hosts === []) {
            $hosts = [$this->site()['apex_domain']];
        }

        return array_values(array_map(static fn ($h) => strtolower((string) $h), $hosts));
    }

    /** Пост без metatitle: <title> = заголовок без суфікса бренду (як у Joomla), а не «заголовок - бренд» рушія. */
    public function postMetaTitleFromTitle(): bool
    {
        return (bool) ($this->data['texts']['post_meta_title_from_title'] ?? false);
    }

    /** Маркер року в мета-заголовках ([Y] у Joomla) → поточний рік; порожньо = не чіпати. */
    public function yearToken(): ?string
    {
        $t = $this->data['texts']['year_token'] ?? null;

        return \is_string($t) && $t !== '' ? $t : null;
    }

    /**
     * Шаблон H1 головної для мови ('Виклик сантехніка в {city_loc} та області'); плейсхолдери {city}, {city_gen},
     * {city_dat}, {city_loc} — відмінки міста. Коли Joomla будує H1 у шаблоні (тип templates поза дампом),
     * а не в полі domains_title. null = брати domains_title + subtitle.
     */
    public function introTitle(string $lang): ?string
    {
        $t = $this->data['texts']['intro_title'][$lang] ?? null;

        return \is_string($t) && trim($t) !== '' ? trim($t) : null;
    }

    /** @return array<string, list<string>> префікси назв районів, які зрізаються (uk/ru) */
    public function districtNamePrefixes(): array
    {
        $out = [];
        foreach ((array) ($this->data['districts']['strip_name_prefixes'] ?? []) as $lang => $list) {
            $out[(string) $lang] = array_values(array_map('strval', (array) $list));
        }

        return $out;
    }

    /**
     * Мови-запасники для російських SEO-полів (*_metatitle_ru, *_meta_description_ru), коли _ru порожнє:
     * joomla_import.ru_fallback_columns: [en]. Порожньо = лише _ru. Коди — [a-z]{2}, інші ігноруються.
     *
     * @return list<string>
     */
    public function ruFallbackColumns(): array
    {
        $out = [];
        foreach ((array) ($this->data['ru_fallback_columns'] ?? []) as $lang) {
            if (\is_string($lang) && preg_match('/^[a-z]{2}$/', $lang)) {
                $out[] = $lang;
            }
        }

        return $out;
    }

    /** @return array<int, int> id категорії Joomla → id ipage, з якого беруться лід/опис/FAQ/SEO сторінки категорії */
    public function pageCategories(): array
    {
        $out = [];
        foreach ((array) ($this->data['pages']['categories'] ?? []) as $cat => $page) {
            $out[(int) $cat] = (int) $page;
        }

        return $out;
    }

    /**
     * Seblod-типи, що стають послугами (ServiceItem). Кожен тип — своя таблиця cck_store_form_<type>,
     * свої назви колонок і своя категорія:
     *   type      — назва типу (catalog, cargo, country…);
     *   slug      — item_content (alias_uk/alias_ru з cck_store_item_content, типово) | content_alias (content.alias для обох мов);
     *   category  — catid (типово: категорія Joomla запису) | <id категорії мапінгу> (усі записи типу в одну категорію);
     *   fields    — поле рушія → основа колонки (<type>_<основа>_<мова>) або {uk: колонка, ru: колонка} повними назвами;
     *   extra     — значення додаткових полів запису (опис полів — app.custom_fields паку):
     *               {ключ: {value: колонка|{uk, ru}, prefix: {column, map: {знач: {uk, ru}}}}}.
     * Без розділу items — один тип catalog з полями першого сайту з Seblod (як до 10.2026).
     *
     * @return list<array{type: string, slug: string, category: string|int, fields: array<string, string|array<string, string>>, extra: array<string, array<string, mixed>>, label: string}>
     */
    public function itemTypes(): array
    {
        $defaults = [
            'title' => 'title', 'lead' => 'lead', 'desc' => 'desc', 'desc2' => 'desc_2', 'desc3' => 'desc_3',
            'tab_title' => 'tabtitle', 'meta_title' => 'metatitle', 'meta_desc' => 'meta_description', 'faq' => 'faq',
            'img' => 'img', 'utp' => 'utp', 'name' => null,
        ];
        $list = $this->data['items'] ?? [['type' => 'catalog']];
        if (!\is_array($list) || $list === []) {
            throw new \InvalidArgumentException('Мапінг: joomla_import.items — непорожній список типів.');
        }
        $out = [];
        foreach ($list as $i => $t) {
            $type = (string) ($t['type'] ?? '');
            if (!preg_match('/^[a-z0-9_]+$/', $type)) {
                throw new \InvalidArgumentException(sprintf('Мапінг: items[%s].type — лише [a-z0-9_].', $i));
            }
            $slug = (string) ($t['slug'] ?? 'item_content');
            if (!\in_array($slug, ['item_content', 'content_alias'], true)) {
                throw new \InvalidArgumentException(sprintf('Мапінг: items[%s].slug — item_content або content_alias.', $i));
            }
            $cat = $t['category'] ?? 'catid';
            $fields = $defaults;
            foreach ((array) ($t['fields'] ?? []) as $k => $v) {
                if (!\array_key_exists($k, $defaults)) {
                    throw new \InvalidArgumentException(sprintf('Мапінг: items[%s].fields.%s — невідоме поле (%s).', $i, $k, implode(', ', array_keys($defaults))));
                }
                $fields[$k] = $v === null || $v === '' ? null : (\is_array($v) ? array_map('strval', $v) : (string) $v);
            }
            $out[] = [
                'type' => $type,
                'slug' => $slug,
                'category' => $cat === 'catid' ? 'catid' : (int) $cat,
                'fields' => array_filter($fields, static fn ($v) => $v !== null),
                'extra' => array_map(static fn ($v): array => (array) $v, (array) ($t['extra'] ?? [])),
                'label' => (string) ($t['label'] ?? $type),
            ];
        }

        return $out;
    }

    /**
     * Тексти категорій із Seblod-типу, що живе на категорії Joomla (cck_store_form_<type>, id = id категорії):
     *   category_source: { type: cargo_category, fields: { title: title, lead: lead, desc: desc, meta_title: meta_title,
     *                      meta_desc: meta_description, faq: faq, img: img } }
     * Значення з мапінгу categories.<id> мають пріоритет. null = розділу нема.
     *
     * @return array{type: string, fields: array<string, string>}|null
     */
    public function categorySource(): ?array
    {
        $src = $this->data['category_source'] ?? null;
        if (!\is_array($src) || empty($src['type'])) {
            return null;
        }
        $fields = ['title' => 'title', 'lead' => 'lead', 'desc' => 'desc', 'meta_title' => 'meta_title', 'meta_desc' => 'meta_description', 'faq' => 'faq', 'img' => 'img'];
        foreach ((array) ($src['fields'] ?? []) as $k => $v) {
            $fields[(string) $k] = (string) $v;
        }

        return ['type' => (string) $src['type'], 'fields' => array_filter($fields, static fn ($v) => $v !== '')];
    }

    /**
     * Пости: Seblod-тип і основи колонок. Типово iblog з полями першого сайту з Seblod (metatitle).
     *
     * @return array{type: string, slug: string, fields: array<string, string>}
     */
    public function posts(): array
    {
        $p = (array) ($this->data['posts'] ?? []);
        $fields = ['title' => 'title', 'lead' => 'lead', 'desc' => 'desc', 'meta_title' => 'metatitle', 'meta_desc' => 'meta_description', 'img' => 'img'];
        foreach ((array) ($p['fields'] ?? []) as $k => $v) {
            $fields[(string) $k] = (string) $v;
        }
        $type = (string) ($p['type'] ?? 'iblog');
        if (!preg_match('/^[a-z0-9_]+$/', $type)) {
            throw new \InvalidArgumentException('Мапінг: posts.type — лише [a-z0-9_].');
        }

        return ['type' => $type, 'slug' => (string) ($p['slug'] ?? 'item_content'), 'fields' => $fields];
    }

    /**
     * Відгуки: таблиця (типово apl_review) і як читати місто. city: legacy — колонка city = id міста (перший сайт з Seblod);
     * location — колонка city = текст «звідки автор» (ServiceReview.location), відгук іде на апекс.
     *
     * @return array{table: string, city: string}
     */
    public function reviews(): array
    {
        $r = (array) ($this->data['reviews'] ?? []);

        return ['table' => (string) ($r['table'] ?? 'apl_review'), 'city' => (string) ($r['city'] ?? 'legacy')];
    }

    /**
     * Команда з окремої таблиці (apl_team: user_uk/ru, text_uk/ru = посада, avatar, state) на апекс-місто.
     * null = команда лише з маркерів domains_team (перший сайт з Seblod) або нема.
     */
    public function teamTable(): ?string
    {
        return self::ident('team.table', $this->data['team']['table'] ?? null);
    }

    /** Категоріям з мапінгу ставити «посадкова» (landing) = це значення; null = не чіпати (як було). */
    public function categoryLanding(int $id): ?bool
    {
        $v = $this->data['categories'][$id]['landing'] ?? $this->data['category_defaults']['landing'] ?? null;

        return $v === null ? null : (bool) $v;
    }

    /**
     * Заголовки H1 у текстах джерела → H2 (texts.demote_h1: true): на сторінці рушія H1 один — у першому екрані;
     * Joomla-тексти деяких сайтів мали власні <h1> усередині контенту. Типово false — тексти як є.
     */
    public function demoteH1(): bool
    {
        return (bool) ($this->data['texts']['demote_h1'] ?? false);
    }

    /**
     * Куди класти текст ipage головної (pages.home): intro (типово — лід+текст одразу під H1, як було), text2 або text3
     * (SEO-блоки нижче на сторінці). Для text2/text3 лід ipage (якщо є) лишається вступом під H1.
     */
    public function homeTextField(): string
    {
        $v = (string) ($this->data['pages']['home_text'] ?? 'intro');

        return \in_array($v, ['intro', 'text2', 'text3'], true) ? $v : 'intro';
    }
}
