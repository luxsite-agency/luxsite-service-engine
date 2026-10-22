<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Тема публічного сайту з паку: кольори, лого, фавікони, og-зображення, хіро.
 *
 * Дефолти — нейтральний вигляд рушія; пак задає в app.theme лише відмінні ключі
 * (config/site/parameters.local.yaml). Невідомий ключ — виняток, щоб перейменування
 * ключа в новій версії рушія не ламало вигляд сайту непомітно (як SiteTheme у shop-engine).
 * Шляхи файлів — від кореня public/, без початкового слеша (themes/<key>/logo.png).
 */
final class SiteTheme
{
    public const DEFAULTS = [
        // ключ паку → клас body.theme-<key> для CSS теми; порожньо = без класу
        'key' => '',
        // CSS-змінні :root у base.html.twig; accent_ink — текстовий відтінок акценту з контрастом 4.5+ на білому
        'colors' => [
            'accent' => '#f98111',
            'accent2' => '#faa125',
            'accent_ink' => '#a35a08',
            'dark' => '#22304e',
            'navy' => '#24334f',
            'footer_bg' => '#f2f6f8',
            'footer_line' => '#d5dce3',
            'footer_text' => '#48474c',
            // основний колір тексту й посилань футера (на темному футері - світлий)
            'footer_ink' => '#22304e',
        ],
        // лого в шапці (42×42, contain); порожньо = квадрат з першою літерою назви сайту
        'logo' => '',
        // фавікони: порожньо = без тегів <link rel=icon>
        'favicon_ico' => '',       // 32×32 .ico
        'favicon_png' => '',       // 192×192 .png
        'apple_touch_icon' => '',  // 180×180 .png
        // ілюстрація хіро головної (1x); порожньо = ServiceSite.heroImagePath з БД (старий шлях /img/…)
        'hero_image' => '',
        // og:image за замовчуванням і image/logo у JSON-LD бізнесу; порожньо = hero сайту (ServiceSite.heroImagePath)
        'og_image' => '',
        // srcset для ілюстрації хіро на головній (ServiceSite.heroImagePath — 1x); порожньо = без srcset
        'hero_srcset' => '',
        // width/height ілюстрації хіро (атрибути img проти стрибка верстки); порожньо = без атрибутів
        'hero_width' => '',
        'hero_height' => '',
        // додатковий CSS теми (підключається після вбудованих стилів); порожньо = нема
        'stylesheet' => '',
        // true = вміст stylesheet вставляється в <style> у <head> замість <link> (без render-blocking запиту; PSI
        // сайту з Joomla-імпортом: ланцюжок link → woff2 коштував ~0,8 с LCP). Відносні url(…) у CSS переписуються від теки файлу.
        'stylesheet_inline' => false,
        // довільні налаштування теми паку (рушій їх не читає): { mega_menu_category: international-transport, … };
        // у шаблонах — theme.option('ключ', дефолт). Кольори поза colors — теж сюди
        'options' => [],
    ];

    /** @var array<string, mixed> тема розгортання: DEFAULTS ⊕ app.theme */
    private readonly array $base;

    /** @var array<string, array<string, mixed>> перекриття тем брендів (app.brands.<slug>.theme), перевірені */
    private readonly array $brandOverrides;

    /** @var array<string, array<string, mixed>> обчислені теми: slug бренду ('' — розгортання) → значення */
    private array $resolved = [];

    /** @var array<string, string> кеш ?v= на запит: шлях → хеш файлу */
    private array $versions = [];

    /**
     * @param array<string, mixed> $overrides app.theme із паку
     * @param array<string, array<string, mixed>> $brands app.brands із паку: { <slug>: { theme: {…ключі DEFAULTS…} } }
     */
    public function __construct(
        #[Autowire('%app.theme%')] array $overrides = [],
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir = '',
        #[Autowire('%app.brands%')] array $brands = [],
        private readonly ?RequestStack $requests = null,
    ) {
        $this->base = self::merge(self::DEFAULTS, $overrides, 'app.theme');
        $perBrand = [];
        foreach ($brands as $slug => $brand) {
            $unknown = array_diff_key((array) $brand, ['theme' => true]);
            if ($unknown !== []) {
                throw new \InvalidArgumentException(sprintf('app.brands.%s: невідомі ключі %s. Дозволені: theme', $slug, implode(', ', array_keys($unknown))));
            }
            $theme = (array) ($brand['theme'] ?? []);
            // перевірка ключів одразу (як і app.theme), щоб одрук у паку валив деплой, а не сторінку бренду
            self::merge(self::DEFAULTS, $theme, 'app.brands.' . $slug . '.theme');
            $perBrand[(string) $slug] = $theme;
        }
        $this->brandOverrides = $perBrand;
    }

    /**
     * Тема поточного бренду (22.10.2026): DEFAULTS ⊕ app.theme ⊕ app.brands.<slug>.theme; key бренду — його slug, якщо
     * пак не задав інший (клас body.theme-<slug>, теки public/themes/<slug>/). Бренд — з головного запиту, тож і в
     * підзапиті сторінки помилки, і в кількох запитах одним ядром (smoke) — свій. Без app.brands — тема розгортання.
     *
     * @return array<string, mixed>
     */
    private function values(): array
    {
        $slug = '';
        if ($this->brandOverrides !== []) {
            $site = $this->requests?->getMainRequest()?->attributes->get(\App\EventSubscriber\ServiceHostSubscriber::ATTR_SITE);
            if ($site instanceof \App\Entity\ServiceSite && isset($this->brandOverrides[$site->getSlug()])) {
                $slug = $site->getSlug();
            }
        }
        if (!isset($this->resolved[$slug])) {
            if ($slug === '') {
                $this->resolved[$slug] = $this->base;
            } else {
                $theme = $this->brandOverrides[$slug];
                $theme['key'] ??= $slug;
                $this->resolved[$slug] = self::merge($this->base, $theme, 'app.brands.' . $slug . '.theme');
            }
        }

        return $this->resolved[$slug];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides, string $where): array
    {
        $unknown = array_diff_key($overrides, self::DEFAULTS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException($where . ': невідомі ключі ' . implode(', ', array_keys($unknown)) . '. Дозволені: ' . implode(', ', array_keys(self::DEFAULTS)));
        }
        if (isset($overrides['colors'])) {
            $unknownColors = array_diff_key((array) $overrides['colors'], self::DEFAULTS['colors']);
            if ($unknownColors !== []) {
                throw new \InvalidArgumentException($where . '.colors: невідомі ключі ' . implode(', ', array_keys($unknownColors)));
            }
            $overrides['colors'] = array_replace($base['colors'], (array) $overrides['colors']);
        }

        return array_replace($base, $overrides);
    }

    public function get(string $key): mixed
    {
        $values = $this->values();
        if (!\array_key_exists($key, $values)) {
            throw new \InvalidArgumentException('SiteTheme: невідомий ключ ' . $key);
        }

        return $values[$key];
    }

    /** Довільна опція теми паку (app.theme.options.<ключ>); нема — $default. */
    public function option(string $key, mixed $default = null): mixed
    {
        $options = $this->values()['options'] ?? [];

        return \is_array($options) && \array_key_exists($key, $options) ? $options[$key] : $default;
    }

    /**
     * Шлях файлу теми як URL від кореня сайту (/themes/<key>/logo.png?v=a1b2c3d4) або null, якщо не задано.
     * ?v= — 8 символів md5 файлу: статика віддається з Cache-Control на місяці (CF browser TTL), без версії правка
     * theme.css/лого в паку не дійшла б до тих, хто вже заходив. Зовнішні http(s) — як є.
     */
    public function url(string $key): ?string
    {
        $path = (string) $this->get($key);
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        $url = str_starts_with($path, '/') ? $path : '/' . $path;
        $v = $this->version($url);

        return $v === null ? $url : $url . '?v=' . $v;
    }

    /** Живий CSS під токеном (ThemeService): public/theme-live/theme.css → <root>/shared/theme/public; null = нема. */
    public function liveCssUrl(): ?string
    {
        $url = '/theme-live/theme.css';
        $v = $this->version($url);

        return $v === null ? null : $url . '?v=' . $v;
    }

    private function version(string $url): ?string
    {
        if (\array_key_exists($url, $this->versions)) {
            return $this->versions[$url] ?: null;
        }
        $file = $this->projectDir . '/public' . $url;
        $hash = ($this->projectDir !== '' && is_file($file)) ? substr((string) md5_file($file), 0, 8) : '';
        $this->versions[$url] = $hash;

        return $hash ?: null;
    }

    /**
     * Вміст stylesheet для <style> у <head> (app.theme.stylesheet_inline): відносні url(fonts/x.woff2), url(hero-m.webp)
     * переписуються від теки файлу (/themes/<key>/…) з ?v=, бо в inline-CSS база URL — сторінка, а не файл теми.
     * null = файлу нема або inline вимкнено (тоді шаблон ставить <link>).
     */
    public function inlineCss(): ?string
    {
        $path = (string) $this->get('stylesheet');
        if (!$this->get('stylesheet_inline') || $path === '' || str_starts_with($path, 'http') || $this->projectDir === '') {
            return null;
        }
        $url = str_starts_with($path, '/') ? $path : '/' . $path;
        $file = $this->projectDir . '/public' . $url;
        if (!is_file($file)) {
            return null;
        }
        $base = rtrim(\dirname($url), '/');
        $css = (string) file_get_contents($file);
        $css = preg_replace_callback(
            '~url\(\s*([\'"]?)(?!data:|https?:|//|/|#)([^\'")]+)\1\s*\)~i',
            function (array $m) use ($base): string {
                $rel = $m[2];
                $target = $base . '/' . ltrim($rel, './');
                $v = $this->version($target);

                return 'url(' . $m[1] . $target . ($v === null ? '' : '?v=' . $v) . $m[1] . ')';
            },
            $css,
        ) ?? $css;
        // </style> усередині CSS закрив би тег — екрануємо на всяк випадок
        return str_ireplace('</style', '<\/style', $css);
    }

    /** @return array<string, string> */
    public function colors(): array
    {
        return $this->values()['colors'];
    }

    /** Рядок CSS-змінних для :root (--accent: …; --accent2: …) */
    public function cssVars(): string
    {
        $out = [];
        foreach ($this->values()['colors'] as $name => $value) {
            $out[] = '--' . str_replace('_', '-', $name) . ': ' . $value . ';';
        }

        return implode(' ', $out);
    }
}
