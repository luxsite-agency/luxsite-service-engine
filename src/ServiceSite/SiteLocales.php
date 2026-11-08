<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Мови розгортання й бренду (22.10.2026): app.locales (перша — без префікса), ServiceSite.locales (підмножина),
 * коди для розмітки app.locale_codes. Одне місце, з якого шаблони й контролери беруть «яка зараз мова» і «якими
 * мовами є сторінка» — замість зашитих uk/ru. При типовому [uk, ru] і бренді без власного набору дає ті самі значення,
 * що були зашиті.
 *
 * app.locale_prefix: false (24.10.2026) — бренди різними мовами в одному розгортанні: префіксів нема, мова за
 * замовчуванням — перша мова бренду (ServiceSite.locales), а не розгортання.
 */
final class SiteLocales
{
    /** Поле коду в app.locale_codes */
    public const CODE_KINDS = ['hreflang', 'og', 'schema', 'label'];

    /**
     * @param list<string> $locales app.locales
     * @param array<string, array<string, string>> $codes app.locale_codes
     */
    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire('%app.locales%')]
        private readonly array $locales = LocalizedRoutes::DECLARED,
        #[Autowire('%app.locale_codes%')]
        private readonly array $codes = [],
        /** framework.default_locale (uk): мова адмінки й хостів без бренду */
        #[Autowire('%kernel.default_locale%')]
        private readonly string $frameworkDefault = 'uk',
        #[Autowire('%app.locale_prefix%')]
        private readonly bool $prefixed = true,
        /** app.hreflang_single: hreflang і для одномовного бренду (сторінка посилається сама на себе + x-default) */
        #[Autowire('%app.hreflang_single%')]
        private readonly bool $hreflangSingle = false,
    ) {
    }

    /** @return list<string> мови розгортання */
    public function deployment(): array
    {
        return $this->locales;
    }

    /** Чи мають мови, крім першої, префікс /<мова>/ (app.locale_prefix) */
    public function prefixed(): bool
    {
        return $this->prefixed;
    }

    /** Мова без префікса в адресі: перша мова розгортання; без префіксів — перша мова поточного бренду */
    public function default(): string
    {
        return $this->defaultFor($this->currentSite());
    }

    /** Мова без префікса для бренду $site (null — хост без бренду: перша мова розгортання) */
    public function defaultFor(?ServiceSite $site): string
    {
        if ($this->prefixed || $site === null) {
            return $this->locales[0];
        }

        return $site->enabledLocales($this->locales)[0] ?? $this->locales[0];
    }

    /**
     * Мови бренду (поточного з головного запиту, якщо не передано) у порядку розгортання.
     *
     * @return list<string>
     */
    public function enabled(?ServiceSite $site = null): array
    {
        $site ??= $this->currentSite();

        return $site === null ? $this->locales : $site->enabledLocales($this->locales);
    }

    /** Мова поточного запиту, якщо вона з мов розгортання; інакше — мова за замовчуванням */
    public function current(): string
    {
        $locale = $this->requests->getCurrentRequest()?->getLocale();

        return \in_array($locale, $this->locales, true) ? $locale : $this->default();
    }

    /**
     * Мова за шляхом головного запиту (/<мова>/…) — для сторінок, що рендеряться поза маршрутом (404, помилки).
     * Без префікса: на хості бренду — мова розгортання за замовчуванням; на хості без бренду (адмінка, невідомий
     * хост) і без запиту — framework.default_locale, щоб адмінка лишалась українською.
     */
    public function fromPath(): string
    {
        $path = $this->requests->getMainRequest()?->getPathInfo() ?? '/';

        return $this->prefixOf($path) ?? ($this->currentSite() === null ? $this->frameworkDefault : $this->default());
    }

    /**
     * Мова сторінки за її шляхом (/<мова>/… або мова без префікса) — для нелокалізованих адрес, що знають сторінку-джерело
     * (форма заявки /lead повертає на неї й веде на «Дякуємо» тією ж мовою).
     */
    public function ofPath(string $path): string
    {
        return $this->prefixOf($path) ?? $this->default();
    }

    private function prefixOf(string $path): ?string
    {
        if (!$this->prefixed) {
            return null;
        }
        foreach (\array_slice($this->locales, 1) as $l) {
            if (str_starts_with($path, '/' . $l . '/')) {
                return $l;
            }
        }

        return null;
    }

    /** Код мови для розмітки: hreflang (uk-UA), og (uk_UA), schema (uk-UA), label (UA); нема запису — сама мова */
    public function code(string $locale, string $kind): string
    {
        if (!\in_array($kind, self::CODE_KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Невідомий вид коду мови «%s»: %s.', $kind, implode(', ', self::CODE_KINDS)));
        }

        return (string) ($this->codes[$locale][$kind] ?? ($kind === 'label' ? strtoupper($locale) : $locale));
    }

    /**
     * Посилання hreflang сторінки — одне джерело для <head> і sitemap.xml. $urls — мова → URL цієї сторінки,
     * $order — мови бренду в потрібному порядку (порожньо — порядок $urls). Перша мова — x-default.
     * Код береться з app.locale_codes[<мова>].hreflang і може містити кілька кодів через пробіл ('en-US en-GB') —
     * тоді на кожен код окреме посилання на той самий URL. Одна мова — порожньо, якщо не ввімкнено app.hreflang_single.
     *
     * @param array<string, string> $urls
     * @param list<string>          $order
     *
     * @return list<array{hreflang: string, href: string}>
     */
    public function hreflangs(array $urls, array $order = []): array
    {
        $langs = $order === [] ? array_keys($urls) : array_values(array_filter($order, static fn (string $l): bool => isset($urls[$l])));
        if ($langs === [] || (\count($langs) < 2 && !$this->hreflangSingle)) {
            return [];
        }
        $out = [];
        foreach ($langs as $l) {
            foreach (preg_split('/\s+/', trim($this->code($l, 'hreflang')), -1, \PREG_SPLIT_NO_EMPTY) ?: [$l] as $code) {
                $out[] = ['hreflang' => $code, 'href' => $urls[$l]];
            }
        }
        $out[] = ['hreflang' => 'x-default', 'href' => $urls[$langs[0]]];

        return $out;
    }

    /** Бренд головного запиту (атрибут ServiceHostSubscriber); null — адмін-хост, CLI, хост без тенанта */
    public function currentSite(): ?ServiceSite
    {
        $site = $this->requests->getMainRequest()?->attributes->get(ServiceHostSubscriber::ATTR_SITE);

        return $site instanceof ServiceSite ? $site : null;
    }
}
