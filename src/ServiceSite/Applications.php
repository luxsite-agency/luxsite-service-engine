<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Форми анкет паку (app.applications): анкета перекладача, резюме, заявка партнера — усе, що не заявка клієнта. Ключ
 * форми → назва, обов'язкові базові поля (name, phone, email), свої поля (text | textarea | list — чекбокси з options),
 * згода на обробку даних, файли (ліміти як у app.lead_files), адреса листа. Рушій приймає лише описані поля — решта з
 * запиту відкидається. Конфігурацію перевіряє конструктор: помилка паку видно на першому ж запиті, а не мовчки.
 *
 *   app.applications:
 *     translator:
 *       title: {uk: Анкета перекладача, ru: Анкета переводчика}
 *       email: hr@example.com              # запасна адреса, якщо в адмінці порожнє «Адреса для анкет»
 *       required: [name, phone, email, languages]
 *       consent: true                      # обов'язкова позначка згоди (поле consent)
 *       files: {max_files: 5, mime: [application/pdf, application/msword, …]}   # нема — без файлів
 *       fields:
 *         languages: {label: {uk: Мови, ru: Языки}}
 *         work: {label: {uk: Види робіт, ru: Виды работ}, type: list, options: {written: {uk: Письмовий, ru: Письменный}, …}}
 *         about: {label: {uk: Про себе, ru: О себе}, type: textarea}
 */
final class Applications
{
    public const string KEY_PATTERN = '/^[a-z0-9_-]{1,32}$/';
    private const array BASE = ['name' => 128, 'phone' => 32, 'email' => 191];
    private const array TYPES = ['text', 'textarea', 'list'];

    /** @var array<string, array{title: array<string, string>, email: string, required: list<string>, consent: bool, files: array<string, mixed>|null, fields: array<string, array{label: array<string, string>, type: string, options: array<string, array<string, string>>}>}> */
    private array $forms = [];

    /** @param array<string, mixed> $config */
    public function __construct(#[Autowire(param: 'app.applications')] array $config)
    {
        foreach ($config as $key => $form) {
            if (!\is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || !\is_array($form)) {
                throw new \InvalidArgumentException(sprintf('app.applications: ключ форми «%s» — [a-z0-9_-], до 32 символів, значення — мапа.', (string) $key));
            }
            $fields = [];
            foreach ((array) ($form['fields'] ?? []) as $name => $f) {
                $f = \is_array($f) ? $f : [];
                $type = (string) ($f['type'] ?? 'text');
                if (!\is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name) !== 1 || isset(self::BASE[$name]) || \in_array($name, ['consent', 'files', 'context'], true)) {
                    throw new \InvalidArgumentException(sprintf('app.applications.%s.fields: «%s» — [a-z0-9_], не name/phone/email/consent/files/context.', $key, (string) $name));
                }
                if (!\in_array($type, self::TYPES, true)) {
                    throw new \InvalidArgumentException(sprintf('app.applications.%s.fields.%s.type: text | textarea | list.', $key, $name));
                }
                $options = [];
                foreach ((array) ($f['options'] ?? []) as $value => $label) {
                    $options[(string) $value] = self::label($label, (string) $value);
                }
                if ($type === 'list' && $options === []) {
                    throw new \InvalidArgumentException(sprintf('app.applications.%s.fields.%s: list потребує options.', $key, $name));
                }
                $fields[$name] = ['label' => self::label($f['label'] ?? null, $name), 'type' => $type, 'options' => $options];
            }
            $fields['context'] ??= ['label' => ['uk' => 'Звідки', 'ru' => 'Откуда'], 'type' => 'text', 'options' => []];
            $required = array_values(array_map('strval', (array) ($form['required'] ?? ['name', 'phone'])));
            foreach ($required as $r) {
                if (!isset(self::BASE[$r]) && !isset($fields[$r])) {
                    throw new \InvalidArgumentException(sprintf('app.applications.%s.required: невідоме поле «%s».', $key, $r));
                }
            }
            $this->forms[$key] = [
                'title' => self::label($form['title'] ?? null, $key),
                'email' => trim((string) ($form['email'] ?? '')),
                'required' => $required,
                'consent' => (bool) ($form['consent'] ?? false),
                'files' => isset($form['files']) && $form['files'] !== false ? (array) $form['files'] : null,
                'fields' => $fields,
            ];
        }
    }

    public function has(string $key): bool
    {
        return isset($this->forms[$key]);
    }

    public function any(): bool
    {
        return $this->forms !== [];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->forms);
    }

    public function title(string $key, string $locale): string
    {
        return $this->forms[$key]['title'][$locale] ?? $this->forms[$key]['title']['uk'] ?? $key;
    }

    public function email(string $key): string
    {
        return $this->forms[$key]['email'] ?? '';
    }

    /** @return array<string, mixed>|null ліміти файлів форми; null — форма без файлів */
    public function files(string $key): ?array
    {
        return $this->forms[$key]['files'] ?? null;
    }

    /**
     * Опис форми для шаблону мовою сторінки.
     *
     * @return array{key: string, title: string, consent: bool, files: bool, required: list<string>, fields: list<array{name: string, label: string, type: string, required: bool, options: list<array{value: string, label: string}>}>}
     */
    public function view(string $key, string $locale): array
    {
        $form = $this->forms[$key] ?? throw new \InvalidArgumentException('Невідома форма анкети: ' . $key);
        $l = $locale;
        $fields = [];
        foreach ($form['fields'] as $name => $f) {
            if ($name === 'context') {
                continue;
            }
            $options = [];
            foreach ($f['options'] as $value => $label) {
                $options[] = ['value' => $value, 'label' => $label[$l] ?? $label['uk']];
            }
            $fields[] = ['name' => $name, 'label' => $f['label'][$l] ?? $f['label']['uk'], 'type' => $f['type'], 'required' => \in_array($name, $form['required'], true), 'options' => $options];
        }

        return ['key' => $key, 'title' => $this->title($key, $locale), 'consent' => $form['consent'], 'files' => $form['files'] !== null, 'required' => $form['required'], 'fields' => $fields];
    }

    /**
     * Поля з запиту: базові (name, phone, email) і data — лише описані у формі, у порядку конфігурації, підписи мовою uk
     * (адмінка й лист — українською). Помилка для людини — у ['error'].
     *
     * @return array{error: ?string, name: ?string, phone: ?string, email: ?string, consent: bool, data: list<array{key: string, label: string, value: string}>}
     */
    public function read(string $key, Request $request, string $locale): array
    {
        $form = $this->forms[$key];
        $ru = $locale === 'ru';
        $en = $locale === 'en';
        $in = $request->request;
        $base = [];
        foreach (self::BASE as $name => $max) {
            $v = self::line($in->get($name, ''));
            $base[$name] = $v !== null ? mb_substr($v, 0, $max) : null;
        }
        $data = [];
        $values = [];
        foreach ($form['fields'] as $name => $f) {
            $raw = $in->all()[$name] ?? null;
            if ($f['type'] === 'list') {
                $picked = array_values(array_filter(array_map('strval', \is_array($raw) ? $raw : [$raw]), static fn (string $v): bool => isset($f['options'][$v])));
                $value = implode(', ', array_map(static fn (string $v): string => $f['options'][$v]['uk'], array_unique($picked)));
            } elseif ($f['type'] === 'textarea') {
                $value = mb_substr(trim(strip_tags(\is_scalar($raw) ? (string) $raw : '')), 0, 5000);
            } else {
                $value = (string) (self::line($raw) ?? '');
                $value = mb_substr($value, 0, 500);
            }
            $values[$name] = $value;
            if ($value !== '') {
                $data[] = ['key' => $name, 'label' => $f['label']['uk'], 'value' => $value];
            }
        }
        $error = null;
        foreach ($form['required'] as $r) {
            if ((isset(self::BASE[$r]) ? (string) $base[$r] : ($values[$r] ?? '')) === '') {
                $label = isset(self::BASE[$r]) ? self::baseLabel($r, $ru, $en) : ($form['fields'][$r]['label'][$locale] ?? $form['fields'][$r]['label']['uk']);
                $error = sprintf($en ? 'Please fill in “%s”.' : ($ru ? 'Заполните поле «%s».' : 'Заповніть поле «%s».'), $label);
                break;
            }
        }
        if ($error === null && $base['phone'] !== null) {
            $digits = preg_replace('/\D/', '', $base['phone']) ?? '';
            if (\strlen($digits) < 9 || \strlen($digits) > 15) {
                $error = $en ? 'Please check your phone number.' : ($ru ? 'Проверьте номер телефона.' : 'Перевірте номер телефону.');
            }
        }
        if ($error === null && $base['email'] !== null && filter_var($base['email'], \FILTER_VALIDATE_EMAIL) === false) {
            $error = $en ? 'Please check your email address.' : ($ru ? 'Проверьте email.' : 'Перевірте email.');
        }
        $consent = filter_var($in->get('consent', false), \FILTER_VALIDATE_BOOLEAN);
        if ($error === null && $form['consent'] && !$consent) {
            $error = $en ? 'Please consent to the processing of your personal data.' : ($ru ? 'Нужно согласие на обработку персональных данных.' : 'Потрібна згода на обробку персональних даних.');
        }

        return ['error' => $error, 'name' => $base['name'], 'phone' => $base['phone'], 'email' => $base['email'], 'consent' => $consent, 'data' => $data];
    }

    private static function baseLabel(string $name, bool $ru, bool $en = false): string
    {
        if ($en) {
            return match ($name) { 'name' => 'Name', 'phone' => 'Phone', default => 'Email' };
        }

        return match ($name) {
            'name' => $ru ? 'Имя' : "Ім'я",
            'phone' => 'Телефон',
            default => 'Email',
        };
    }

    /** @return array<string, string> */
    private static function label(mixed $label, string $fallback): array
    {
        if (\is_string($label) && $label !== '') {
            return ['uk' => $label, 'ru' => $label];
        }
        $uk = \is_array($label) ? (string) ($label['uk'] ?? '') : '';
        $ru = \is_array($label) ? (string) ($label['ru'] ?? '') : '';
        $uk = $uk !== '' ? $uk : $fallback;

        $out = ['uk' => $uk, 'ru' => $ru !== '' ? $ru : $uk];
        foreach (\is_array($label) ? $label : [] as $locale => $value) {
            if (\is_string($locale) && preg_match('/^[a-z]{2}$/', $locale) && \is_string($value) && $value !== '') {
                $out[$locale] = $value;
            }
        }

        return $out;
    }

    private static function line(mixed $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags(\is_scalar($value) ? (string) $value : '')));

        return $value !== '' ? $value : null;
    }
}
