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
    ) {
    }

    /** @return list<string> мови розгортання */
    public function deployment(): array
    {
        return $this->locales;
    }

    /** Мова без префікса в адресі */
    public function default(): string
    {
        return $this->locales[0];
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
        foreach (\array_slice($this->locales, 1) as $l) {
            if (str_starts_with($path, '/' . $l . '/')) {
                return $l;
            }
        }

        return null;
    }

    /**
     * Чи потрібен запису власний переклад, щоб існувати цією мовою: так для мов без фолбеку на uk (en), коли вона
     * не основна в розгортанні. [uk, ru, en] — en-сторінка лише з англійськими полями; [en] — як і раніше, усі записи.
     */
    public function needsOwnContent(string $locale): bool
    {
        return $locale !== $this->default() && !\in_array($locale, ['uk', 'ru'], true);
    }

    /** Запис є мовою $locale (поточною, якщо не передано): перевірка LocalizedFieldsTrait::translatedIn, де вона потрібна */
    public function has(object $entity, ?string $locale = null): bool
    {
        $locale ??= $this->current();

        return !$this->needsOwnContent($locale) || !method_exists($entity, 'translatedIn') || $entity->translatedIn($locale);
    }

    /**
     * Лише записи, що є мовою $locale (поточною, якщо не передано) — списки на /en/ без неперекладених послуг і сторінок.
     *
     * @template T of object
     *
     * @param iterable<T> $entities
     *
     * @return list<T>
     */
    public function only(iterable $entities, ?string $locale = null): array
    {
        $locale ??= $this->current();
        $out = [];
        foreach ($entities as $e) {
            if ($this->has($e, $locale)) {
                $out[] = $e;
            }
        }

        return $out;
    }

    /**
     * Мова юридичної сторінки (privacy | terms): $locale, якщо типовий текст рушія нею є (uk, ru) або бренд має свій текст
     * (ServiceSite.privacyEn…); інакше null — en-сторінки нема (типовий текст рушія лише uk/ru), посилання ведуть на
     * основну мову (legalLocale(…) ?? default()).
     */
    public function legalLocale(ServiceSite $site, string $field, string $locale): ?string
    {
        if (!$this->needsOwnContent($locale)) {
            return $locale;
        }
        $text = $site->loc($field, $locale);

        return \is_string($text) && trim($text) !== '' ? $locale : null;
    }

    /** Код мови для розмітки: hreflang (uk-UA), og (uk_UA), schema (uk-UA), label (UA); нема запису — сама мова */
    public function code(string $locale, string $kind): string
    {
        if (!\in_array($kind, self::CODE_KINDS, true)) {
            throw new \InvalidArgumentException(sprintf('Невідомий вид коду мови «%s»: %s.', $kind, implode(', ', self::CODE_KINDS)));
        }

        return (string) ($this->codes[$locale][$kind] ?? ($kind === 'label' ? strtoupper($locale) : $locale));
    }

    /** Бренд головного запиту (атрибут ServiceHostSubscriber); null — адмін-хост, CLI, хост без тенанта */
    public function currentSite(): ?ServiceSite
    {
        $site = $this->requests->getMainRequest()?->attributes->get(ServiceHostSubscriber::ATTR_SITE);

        return $site instanceof ServiceSite ? $site : null;
    }
}
