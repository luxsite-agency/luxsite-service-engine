<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

/**
 * Зміст статті (ТЗ блогу NRG, 08.11.2026): панель навігації зліва будується з H2/H3 тексту статті,
 * тож заголовкам потрібні стабільні id, а шаблону - плаский список рівнів. Робимо на сервері, а не
 * скриптом у браузері: зміст має бути в HTML (пошук, читання без JS) і не смикати верстку після кадру.
 *
 * Текст статей редактори пишуть в адмінці, тож це довірений HTML: обходимось регуляркою по h2/h3
 * без DOM-парсера (жодної залежності, нічого не переписується, крім додавання id).
 */
final class ArticleOutline
{
    /** Швидкість читання, слів за хвилину: середнє для ділового тексту; «2 min read» у ТЗ. */
    public const int WORDS_PER_MINUTE = 200;

    private const string HEADINGS = '/<h([23])\b([^>]*)>(.*?)<\/h\1\s*>/is';

    /**
     * Заголовки статті для панелі навігації.
     *
     * @return list<array{level: int, id: string, text: string}>
     */
    public function headings(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }
        $out = [];
        $seen = [];
        preg_match_all(self::HEADINGS, $html, $m, PREG_SET_ORDER);
        foreach ($m as $h) {
            $text = $this->plain($h[3]);
            if ($text === '') {
                continue;
            }
            $out[] = [
                'level' => (int) $h[1],
                'id' => $this->idFor($h[2], $text, $seen),
                'text' => $text,
            ];
        }

        return $out;
    }

    /** Той самий HTML, але з id на H2/H3 - щоб посилання зі змісту вели в текст. */
    public function withAnchors(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }
        $seen = [];

        return (string) preg_replace_callback(self::HEADINGS, function (array $h) use (&$seen): string {
            $text = $this->plain($h[3]);
            if ($text === '') {
                return $h[0];
            }
            $id = $this->idFor($h[2], $text, $seen);
            $attrs = $this->hasId($h[2]) ? $h[2] : rtrim($h[2]) . ' id="' . $id . '"';

            return '<h' . $h[1] . $attrs . '>' . $h[3] . '</h' . $h[1] . '>';
        }, $html);
    }

    /** Хвилини читання, мінімум 1: «2 min read» під датою публікації. */
    public function readMinutes(?string ...$parts): int
    {
        $words = 0;
        foreach ($parts as $part) {
            $text = $this->plain((string) $part);
            $words += $text === '' ? 0 : \count(preg_split('/\s+/u', $text) ?: []);
        }

        return max(1, (int) ceil($words / self::WORDS_PER_MINUTE));
    }

    /** Готовий id заголовка: уже заданий у розмітці - беремо його, інакше слаг з тексту; дублі - з -2, -3. */
    private function idFor(string $attrs, string $text, array &$seen): string
    {
        if (preg_match('/\bid\s*=\s*(["\'])(.*?)\1/i', $attrs, $m) === 1 && trim($m[2]) !== '') {
            return trim($m[2]);
        }
        $base = $this->slug($text);
        $id = $base;
        for ($n = 2; isset($seen[$id]); ++$n) {
            $id = $base . '-' . $n;
        }
        $seen[$id] = true;

        return $id;
    }

    private function hasId(string $attrs): bool
    {
        return preg_match('/\bid\s*=\s*(["\'])(.*?)\1/i', $attrs, $m) === 1 && trim($m[2]) !== '';
    }

    /** Текст заголовка без розмітки й сутностей, в один рядок. */
    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Слаг для id: латиниця, цифри й дефіси. Умлаути й кирилицю транслітеруємо самі - iconv на
     * Windows і в alpine віддає різне, а id мають бути однакові скрізь (посилання зі змісту).
     */
    private function slug(string $text): string
    {
        $map = [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'à' => 'a', 'â' => 'a', 'ç' => 'c', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ie',
            'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'i', 'й' => 'i', 'к' => 'k', 'л' => 'l',
            'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ю' => 'iu',
            'я' => 'ia', 'ь' => '', 'ы' => 'y', 'э' => 'e', 'ё' => 'e', 'ъ' => '',
            // апостроф прибираємо, а не міняємо на дефіс: «зв'язок» → zviazok, не zv-iazok
            "'" => '', "\u{2019}" => '', "\u{02BC}" => '', '`' => '',
        ];
        $slug = strtr(mb_strtolower($text, 'UTF-8'), $map);
        $slug = (string) preg_replace('/[^a-z0-9]+/u', '-', $slug);
        $slug = trim($slug, '-');

        return $slug === '' ? 'section' : mb_substr($slug, 0, 64);
    }
}
