<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Довільні поля форми заявки, які дозволяє пак (26.11.2026), без нових колонок і коду:
 *
 *   app.lead.extra_fields: [address, topic, {key: showing_day, label: 'День показу'}]
 *
 * POST /lead приймає лише перелічені ключі ([a-z0-9_], до 40 символів; не імена власних полів форми рушія), значення —
 * короткі рядки без розмітки (до 500 символів), лягають у JSON-колонку заявки (ServiceLead::$extra: {ключ: значення}).
 * Незнайомі ключі відкидаються. Підпис (label) — для адмінки, листа й CRM; без нього показується ключ.
 */
final class LeadExtraFields
{
    public const int VALUE_MAX = 500;

    /** Власні поля форми заявки й атрибуції: довільним полем їх не оголосити */
    private const array RESERVED = [
        'phone', 'name', 'message', 'email', 'service', 'context', 'files', 'website', 'lead_type',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'click_id', 'gclid', 'referrer', 'landing_url',
    ];

    /** @var array<string, string> ключ → підпис */
    private readonly array $labels;

    /** @param array<int, mixed> $config app.lead.extra_fields */
    public function __construct(
        #[Autowire('%app.lead.extra_fields%')]
        array $config = [],
    ) {
        $labels = [];
        foreach ($config as $field) {
            $key = \is_array($field) ? (string) ($field['key'] ?? '') : (string) $field;
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1 || \in_array($key, self::RESERVED, true)) {
                throw new \InvalidArgumentException(sprintf('app.lead.extra_fields: ключ «%s» — латинська літера, далі [a-z0-9_], до 40 символів, і не власне поле форми заявки (%s).', $key, implode(', ', self::RESERVED)));
            }
            $label = \is_array($field) ? trim((string) ($field['label'] ?? '')) : '';
            $labels[$key] = $label !== '' ? $label : $key;
        }
        $this->labels = $labels;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->labels);
    }

    /**
     * Значення дозволених полів з тіла форми: рядок без тегів і переносів, до VALUE_MAX; порожні й не-рядки відкидаються.
     *
     * @param array<string, mixed> $post
     *
     * @return array<string, string>
     */
    public function collect(array $post): array
    {
        $out = [];
        foreach ($this->labels as $key => $_) {
            $value = $post[$key] ?? null;
            if (!\is_scalar($value)) {
                continue;
            }
            $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));
            if ($value !== '') {
                $out[$key] = mb_substr($value, 0, self::VALUE_MAX);
            }
        }

        return $out;
    }

    /**
     * Рядки «підпис: значення» для адмінки, листа й CRM: спершу поля паку в його порядку, далі ключі, яких у паку вже нема
     * (стара заявка) — за ключем.
     *
     * @param array<string, string>|null $extra
     *
     * @return list<string>
     */
    public function lines(?array $extra): array
    {
        $extra = (array) $extra;
        $rows = [];
        foreach ($this->labels as $key => $label) {
            if (isset($extra[$key]) && $extra[$key] !== '') {
                $rows[] = $label . ': ' . $extra[$key];
            }
        }
        foreach ($extra as $key => $value) {
            if (!isset($this->labels[$key]) && \is_scalar($value) && (string) $value !== '') {
                $rows[] = $key . ': ' . $value;
            }
        }

        return $rows;
    }
}
