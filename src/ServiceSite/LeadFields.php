<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Додаткові поля форми заявки: запит на розрахунок (маршрут, вага, об'єм) чи запис на прийом (послуга, лікар, дата).
 * Набір полів задає пак (app.lead_fields), за замовчуванням порожньо — форма як була (телефон, ім'я, повідомлення):
 *
 *   app.lead_fields:
 *       - key: from                                  # [a-z0-9_]; у формі — name="details[from]"
 *         label: { uk: Пункт відправлення, ru: Пункт отправления }
 *         type: text                                 # text | number | email | tel | select | date
 *         unit: { uk: кг, ru: кг }                   # одиниця в підписі через кому («Вага, кг»)
 *         required: true
 *         group: { uk: Маршрут, ru: Маршрут }        # підзаголовок групи полів (fieldset) у формі
 *       - key: service
 *         type: select
 *         source: services                           # services (послуги міста) | team (команда міста) | list | інше джерело
 *         placeholder: { uk: Оберіть послугу, ru: Выберите услугу }
 *       - key: branch_time
 *         type: select
 *         source: list                               # свій список: рядки або { value, label: { uk, ru } }
 *         options: [ Ранок, { value: evening, label: { uk: Вечір, ru: Вечер } } ]
 *       - key: date
 *         type: date
 *         min_days: 0                                # найраніша дата — сьогодні + N днів (дефолт 0)
 *         max_days: 60                               # найпізніша — сьогодні + N днів (дефолт 366)
 *
 * Варіанти select беруться для міста запиту (філія = місто, окремого вибору нема). Джерело, якого рушій не знає
 * (розділ ще не встановлено), або порожній список — поле не показується й не перевіряється: форма не ламається.
 *
 * Значення зберігаються в ServiceLead.details (JSON: select — підпис обраного варіанта українською, date — Y-m-d),
 * а читабельним блоком «Поле: значення» дописуються в message — тож лист, адмінка, примітка Pipedrive і COMMENTS
 * Bitrix24 бачать їх без окремого коду. Для полів CRM — джерело detail.<key> у мапі app.crm.bitrix24; поле
 * type: email — ще й джерело email (контакт Bitrix24), select із source: services — джерело service.
 */
final class LeadFields implements ResetInterface
{
    public const MAX_LENGTH = 255;
    public const TYPES = ['text', 'number', 'email', 'tel', 'select', 'date'];

    /** Імена, зайняті самою формою. */
    private const RESERVED = ['phone', 'name', 'message', 'website', 'lead_type', 'consent'];

    private const DATE_MAX_DAYS = 366;

    /**
     * @var list<array{key: string, label: array<string, string>, type: string, unit: array<string, string>, required: bool,
     *     group: array<string, string>, placeholder: array<string, string>, source: ?string,
     *     options: list<array{value: string, label: array{uk: string, ru: string}}>, min_days: int, max_days: int}>
     */
    private readonly array $fields;

    /** @var iterable<LeadOptionSource> */
    private readonly iterable $sourceList;

    /** @var array<string, LeadOptionSource>|null */
    private ?array $sources = null;

    /** @var array<string, list<array{value: string, label: array{uk: string, ru: string}}>> memo на запит: джерело@місто */
    private array $optionsMemo = [];

    /** @var array<string, true> про невідоме джерело пишемо в лог один раз */
    private array $warned = [];

    /**
     * @param list<array<string, mixed>> $config  app.lead_fields
     * @param iterable<LeadOptionSource>  $sources
     */
    public function __construct(
        #[Autowire('%app.lead_fields%')]
        array $config = [],
        #[AutowireIterator('app.lead_option_source')]
        iterable $sources = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
        $fields = [];
        $seen = [];
        foreach ($config as $i => $f) {
            $f = (array) $f;
            $key = (string) ($f['key'] ?? '');
            if (!preg_match('/^[a-z0-9_]{1,40}$/', $key) || isset($seen[$key]) || \in_array($key, self::RESERVED, true)) {
                throw new \InvalidArgumentException(sprintf('app.lead_fields[%s]: ключ «%s» — [a-z0-9_], унікальний, не %s.', $i, $key, implode('/', self::RESERVED)));
            }
            $type = (string) ($f['type'] ?? 'text');
            if (!\in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException(sprintf('app.lead_fields.%s: type — %s.', $key, implode('|', self::TYPES)));
            }
            $seen[$key] = true;

            $source = null;
            $options = [];
            if ($type === 'select') {
                $source = (string) ($f['source'] ?? (isset($f['options']) ? 'list' : ''));
                if (!preg_match('/^[a-z0-9_]{1,40}$/', $source)) {
                    throw new \InvalidArgumentException(sprintf('app.lead_fields.%s: для select потрібне source (services, team, list…).', $key));
                }
                if ($source === 'list') {
                    $options = self::listOptions($key, (array) ($f['options'] ?? []));
                }
            }
            $minDays = (int) ($f['min_days'] ?? 0);
            $maxDays = (int) ($f['max_days'] ?? self::DATE_MAX_DAYS);
            if ($type === 'date' && $minDays > $maxDays) {
                throw new \InvalidArgumentException(sprintf('app.lead_fields.%s: min_days > max_days.', $key));
            }

            $fields[] = [
                'key' => $key,
                'label' => self::pair($f['label'] ?? null, $key),
                'type' => $type,
                'unit' => self::pair($f['unit'] ?? null),
                'required' => (bool) ($f['required'] ?? false),
                'group' => self::pair($f['group'] ?? null),
                'placeholder' => self::pair($f['placeholder'] ?? null),
                'source' => $source,
                'options' => $options,
                'min_days' => $minDays,
                'max_days' => $maxDays,
            ];
        }
        $this->fields = $fields;
        $this->sourceList = $sources;
    }

    public function enabled(): bool
    {
        return $this->fields !== [];
    }

    /** Між запитами (воркер) варіанти міста перечитуються. */
    public function reset(): void
    {
        $this->optionsMemo = [];
    }

    /**
     * Поля для шаблону мовою сторінки. select без варіантів у цьому місті (невідоме джерело, порожній список) — пропущено.
     *
     * @param array<string, string> $preset джерело => value, що обрано заздалегідь ({services: slug_uk} на сторінці послуги)
     *
     * @return list<array{key: string, name: string, label: string, label_full: string, type: string, unit: string,
     *     required: bool, group: string, placeholder: string, options: list<array{value: string, label: string, selected: bool}>,
     *     min: ?string, max: ?string}>
     */
    public function forForm(string $locale, ?ServiceCity $city = null, array $preset = [], ?\DateTimeImmutable $today = null): array
    {
        $lang = $locale === 'ru' ? 'ru' : 'uk';
        $today ??= new \DateTimeImmutable('today');
        $out = [];
        foreach ($this->fields as $f) {
            $options = [];
            if ($f['type'] === 'select') {
                $choices = $this->optionsFor($f, $city);
                if ($choices === []) {
                    continue;
                }
                $want = (string) ($preset[(string) $f['source']] ?? '');
                foreach ($choices as $o) {
                    $options[] = ['value' => $o['value'], 'label' => $o['label'][$lang], 'selected' => $want !== '' && $o['value'] === $want];
                }
            }
            $out[] = [
                'key' => $f['key'],
                'name' => 'details[' . $f['key'] . ']',
                'label' => $f['label'][$lang],
                'label_full' => $f['label'][$lang] . ($f['unit'][$lang] !== '' ? ', ' . $f['unit'][$lang] : ''),
                'type' => $f['type'],
                'unit' => $f['unit'][$lang],
                'required' => $f['required'],
                'group' => $f['group'][$lang],
                'placeholder' => $f['placeholder'][$lang],
                'options' => $options,
                'min' => $f['type'] === 'date' ? $today->modify(sprintf('%+d days', $f['min_days']))->format('Y-m-d') : null,
                'max' => $f['type'] === 'date' ? $today->modify(sprintf('%+d days', $f['max_days']))->format('Y-m-d') : null,
            ];
        }

        return $out;
    }

    /**
     * Перевірка й очищення details[...] з форми: лише описані поля, обов'язкові — непорожні, number — число
     * (кома як десятковий роздільник дозволена), email — валідна адреса, select — один із варіантів міста (зберігається
     * підпис українською), date — Y-m-d у межах min_days..max_days (± день на різницю поясів), довжина ≤ MAX_LENGTH.
     *
     * @param mixed $input request->request->all()['details']
     *
     * @return array{0: array<string, string>, 1: list<string>} [чисті значення, ключі полів з помилкою]
     */
    public function validate(mixed $input, ?ServiceCity $city = null, ?\DateTimeImmutable $today = null): array
    {
        $input = \is_array($input) ? $input : [];
        $today ??= new \DateTimeImmutable('today');
        $clean = [];
        $errors = [];
        foreach ($this->fields as $f) {
            $choices = [];
            if ($f['type'] === 'select') {
                $choices = $this->optionsFor($f, $city);
                if ($choices === []) {
                    // поля не було у формі — і вимагати нічого
                    continue;
                }
            }
            $raw = $input[$f['key']] ?? '';
            $value = \is_scalar($raw) ? trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '') : '';
            if ($value === '') {
                if ($f['required']) {
                    $errors[] = $f['key'];
                }
                continue;
            }
            if (mb_strlen($value) > self::MAX_LENGTH) {
                $errors[] = $f['key'];
                continue;
            }
            $stored = match ($f['type']) {
                'number' => preg_match('/^\d{1,9}([.,]\d{1,3})?$/', $value) ? str_replace(',', '.', $value) : null,
                'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null,
                'tel' => preg_match('/^[\d\s()+-]{5,32}$/', $value) ? $value : null,
                'select' => self::choiceLabel($choices, $value),
                'date' => self::checkDate($value, $today, $f['min_days'], $f['max_days']),
                default => $value,
            };
            if ($stored === null) {
                $errors[] = $f['key'];
                continue;
            }
            $clean[$f['key']] = $stored;
        }

        return [$clean, $errors];
    }

    /**
     * Читабельний блок для message/листа/CRM: «Послуга: Консультація», «Вага: 1200 кг», «Дата: 25.10.2026» (підписи
     * українською — менеджер читає одною мовою).
     *
     * @param array<string, mixed> $details
     */
    public function summary(array $details): string
    {
        $rows = [];
        foreach ($this->fields as $f) {
            $value = (string) ($details[$f['key']] ?? '');
            if ($value === '') {
                continue;
            }
            if ($f['type'] === 'date' && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) !== false) {
                $value = $d->format('d.m.Y');
            }
            $unit = $f['unit']['uk'] !== '' ? ' ' . $f['unit']['uk'] : '';
            $rows[] = $f['label']['uk'] . ': ' . $value . $unit;
        }

        return implode("\n", $rows);
    }

    /** Ключ поля type: email (перше), щоб CRM взяла його як email контакту; null — нема. */
    public function emailKey(): ?string
    {
        foreach ($this->fields as $f) {
            if ($f['type'] === 'email') {
                return $f['key'];
            }
        }

        return null;
    }

    /** Ключ поля select із source: services (перше) — обрана послуга для CRM (джерело service); null — нема. */
    public function serviceKey(): ?string
    {
        foreach ($this->fields as $f) {
            if ($f['type'] === 'select' && $f['source'] === 'services') {
                return $f['key'];
            }
        }

        return null;
    }

    /**
     * @param array{type: string, source: ?string, options: list<array{value: string, label: array{uk: string, ru: string}}>, key: string} $f
     *
     * @return list<array{value: string, label: array{uk: string, ru: string}}>
     */
    private function optionsFor(array $f, ?ServiceCity $city): array
    {
        if ($f['source'] === 'list') {
            return $f['options'];
        }
        $source = $this->source((string) $f['source']);
        if ($source === null) {
            if (!isset($this->warned[$f['key']])) {
                $this->warned[$f['key']] = true;
                $this->logger?->warning(sprintf('app.lead_fields.%s: джерело «%s» рушій не знає — поле не показується.', $f['key'], $f['source']));
            }

            return [];
        }
        if ($city === null) {
            return [];
        }

        return $this->optionsMemo[$f['source'] . '@' . spl_object_id($city)] ??= array_values(array_filter(
            $source->options($city),
            static fn (array $o): bool => $o['value'] !== '' && $o['label']['uk'] !== '',
        ));
    }

    private function source(string $name): ?LeadOptionSource
    {
        if ($this->sources === null) {
            $this->sources = [];
            foreach ($this->sourceList as $s) {
                $this->sources[$s->name()] = $s;
            }
        }

        return $this->sources[$name] ?? null;
    }

    /**
     * @param list<array{value: string, label: array{uk: string, ru: string}}> $choices
     */
    private static function choiceLabel(array $choices, string $value): ?string
    {
        foreach ($choices as $o) {
            if ($o['value'] === $value) {
                return $o['label']['uk'];
            }
        }

        return null;
    }

    private static function checkDate(string $value, \DateTimeImmutable $today, int $minDays, int $maxDays): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return null;
        }
        // ± день: «сьогодні» браузера й сервера можуть розійтися через часовий пояс
        $min = $today->modify(sprintf('%+d days', $minDays - 1));
        $max = $today->modify(sprintf('%+d days', $maxDays + 1));

        return $d >= $min && $d <= $max ? $value : null;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<array{value: string, label: array{uk: string, ru: string}}>
     */
    private static function listOptions(string $key, array $raw): array
    {
        $out = [];
        $seen = [];
        foreach ($raw as $o) {
            if (\is_scalar($o)) {
                $label = ['uk' => trim((string) $o), 'ru' => trim((string) $o)];
                $value = $label['uk'];
            } else {
                $o = (array) $o;
                $label = self::pair($o['label'] ?? $o);
                $value = trim((string) ($o['value'] ?? $label['uk']));
            }
            if ($label['uk'] === '' || $value === '' || isset($seen[$value])) {
                throw new \InvalidArgumentException(sprintf('app.lead_fields.%s: варіант списку — непорожній підпис uk і унікальний value.', $key));
            }
            $seen[$value] = true;
            $out[] = ['value' => $value, 'label' => $label];
        }
        if ($out === []) {
            throw new \InvalidArgumentException(sprintf('app.lead_fields.%s: source: list без options.', $key));
        }

        return $out;
    }

    /** @return array{uk: string, ru: string} {uk, ru} з паку; ru порожнє = uk; рядок — однаковий для обох мов */
    private static function pair(mixed $v, string $default = ''): array
    {
        if (\is_scalar($v)) {
            $v = ['uk' => (string) $v];
        }
        $v = \is_array($v) ? $v : [];
        $uk = trim((string) ($v['uk'] ?? '')) ?: $default;
        $ru = trim((string) ($v['ru'] ?? '')) ?: $uk;

        return ['uk' => $uk, 'ru' => $ru];
    }
}
