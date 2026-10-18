<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Import;

/**
 * Розбір «шаблонних» полів Seblod (Joomla CCK). Повторювані групи зберігаються одним текстом
 * з маркерами виду  ::<група>_<ключ>|<індекс>|<поле>::значення::/<група>_<ключ>|<індекс>|<поле>::
 * (між ними <br />). Напр. FAQ міста: template_faq_title|0|domains_faq_page_uk / template_faq_desc|0|…,
 * команда: team_template_img|0|domains_team_uk / team_template_title / team_template_desc.
 */
final class SeblodMarkers
{
    /**
     * Групує значення за індексом: [0 => ['template_faq_title' => '…', 'template_faq_desc' => '…'], 1 => …].
     *
     * @return array<int, array<string, string>>
     */
    public static function groups(string $raw, string $field): array
    {
        if ($raw === '' || !str_contains($raw, '|' . $field . '::')) {
            return [];
        }
        $f = preg_quote($field, '#');
        preg_match_all('#::([a-z0-9_]+)\|(\d+)\|' . $f . '::(.*?)::/\1\|\2\|' . $f . '::#s', $raw, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $hit) {
            $out[(int) $hit[2]][$hit[1]] = trim($hit[3]);
        }
        ksort($out);

        return $out;
    }

    /**
     * FAQ з маркерів template_faq_title / template_faq_desc (або tmpl_faq_*) → [{q, a}] (порожні пари відкидаються;
     * відповідь — чистий текст, бо шаблон рушія екранує й робить nl2br).
     *
     * @return list<array{q: string, a: string}>
     */
    public static function faq(string $raw, string $field): array
    {
        $items = [];
        foreach (self::groups($raw, $field) as $g) {
            // перший сайт: template_faq_*, другий: tmpl_faq_* (різні шаблони полів Seblod)
            $q = self::text($g['template_faq_title'] ?? $g['tmpl_faq_title'] ?? '');
            $a = self::text($g['template_faq_desc'] ?? $g['tmpl_faq_desc'] ?? '');
            if ($q !== '' && $a !== '') {
                $items[] = ['q' => $q, 'a' => $a];
            }
        }

        return $items;
    }

    /**
     * Команда з маркерів team_template_img / _title / _desc → [{img, name, role}].
     *
     * @return list<array{img: string, name: string, role: string}>
     */
    public static function team(string $raw, string $field): array
    {
        $items = [];
        foreach (self::groups($raw, $field) as $g) {
            $name = self::text($g['team_template_title'] ?? '');
            if ($name === '') {
                continue;
            }
            $items[] = [
                'img' => trim($g['team_template_img'] ?? ''),
                'name' => $name,
                'role' => self::text($g['team_template_desc'] ?? ''),
            ];
        }

        return $items;
    }

    /** HTML → чистий текст: абзаци й <br> → переноси рядків, сутності розкодовано, пробіли стиснуто. */
    public static function text(string $html): string
    {
        $s = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $s = preg_replace('#</(p|div|li|h[1-6])>\s*#i', "\n", $s) ?? $s;
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\u{a0}", ' ', $s);
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? $s;
        $s = preg_replace('/\s*\n\s*/', "\n", $s) ?? $s;

        return trim($s);
    }
}
