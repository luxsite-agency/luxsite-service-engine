<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Html;

/**
 * Коментарі розробника геть із того, що бачить відвідувач у view-source (вимога власника 25.10.2026): HTML `<!-- -->`,
 * CSS `/* *\/` у `<style>` і файлах теми, JS `/* *\/` та `// …` в інлайн-скриптах і файлах теми.
 *
 * Без DOM: один прохід по тексту, стрибками preg_match від цікавого токена до наступного, тому 130 КБ сторінки — одиниці
 * мілісекунд. Що лишається як є: умовні коментарі `<!--[if …]>…<![endif]-->`, вміст `<pre>`, `<textarea>`, `<code>`,
 * атрибути, `<script>` не з JavaScript (`application/ld+json`, `text/template`…), ліцензійні `/*! … *\/`.
 * JS-токенайзер розрізняє рядки (' " `), шаблонні рядки з `${…}`, регулярні вирази (за попереднім токеном) і коментарі;
 * коли розбір непевний (незакритий рядок, regex без кінця рядка) — повертає null, і скрипт лишається з коментарями:
 * краще коментар, ніж зламаний JS.
 */
final class CommentStripper
{
    /** Значення type, за якими <script> — це JavaScript (порожнє = теж). */
    private const JS_TYPES = ['', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript', 'text/jscript', 'text/x-javascript'];

    /** Після цих слів `/` починає регулярний вираз, а не ділення. */
    private const REGEX_KEYWORDS = ['return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await'];

    /** Після цих символів `/` — початок регулярного виразу (крім `++`/`--`, див. isRegexStart). */
    private const REGEX_PREFIX_CHARS = '(,=:[!&|?{};+-*%<>~^';

    public function html(string $html): string
    {
        $out = '';
        $pos = 0;
        $len = \strlen($html);
        while ($pos < $len && preg_match('~<!--|<(script|style|pre|textarea|code)(?=[\s>/])|<[a-zA-Z]~i', $html, $m, \PREG_OFFSET_CAPTURE, $pos)) {
            $at = (int) $m[0][1];
            $out .= substr($html, $pos, $at - $pos);

            if (!isset($m[1]) || $m[1][1] === -1 || $m[1][0] === '') {
                if ($m[0][0] !== '<!--') {
                    // звичайний тег: перестрибнути цілком, щоб <!-- чи /* в атрибутах не зачепити
                    if (preg_match('~\G<[a-zA-Z][^>"\']*(?:"[^"]*"[^>"\']*|\'[^\']*\'[^>"\']*)*>~', $html, $t, 0, $at)) {
                        $out .= $t[0];
                        $pos = $at + \strlen($t[0]);
                    } else {
                        $out .= '<';
                        $pos = $at + 1;
                    }
                    continue;
                }
                $end = strpos($html, '-->', $at + 4);
                if ($end === false) {
                    return $out . substr($html, $at);
                }
                $end += 3;
                $comment = substr($html, $at, $end - $at);
                if (preg_match('~^<!--(?:\[if\b|<!\[endif\])~i', $comment)) {
                    $out .= $comment;
                } else {
                    $end = $this->dropLineIfEmpty($out, $html, $end);
                }
                $pos = $end;
                continue;
            }

            $tag = strtolower($m[1][0]);
            $gt = strpos($html, '>', $at);
            if ($gt === false) {
                return $out . substr($html, $at);
            }
            $open = substr($html, $at, $gt + 1 - $at);
            $close = stripos($html, '</' . $tag, $gt + 1);
            if ($close === false) {
                return $out . substr($html, $at);
            }
            $body = substr($html, $gt + 1, $close - $gt - 1);
            $out .= $open;
            if ($tag === 'script') {
                $out .= $this->isJavaScript($open) ? ($this->js($body) ?? $body) : $body;
            } elseif ($tag === 'style') {
                $out .= $this->css($body);
            } else {
                $out .= $body;
            }
            $pos = $close;
        }

        return $out . substr($html, $pos);
    }

    /** `/* … *\/` поза рядками й url(…); порожні рядки, що лишилися, схлопуються. `/*! … *\/` (ліцензії) лишається. */
    public function css(string $css): string
    {
        $out = '';
        $pos = 0;
        $len = \strlen($css);
        while ($pos < $len && preg_match('~/\*|["\']|url\(~i', $css, $m, \PREG_OFFSET_CAPTURE, $pos)) {
            $at = (int) $m[0][1];
            $tok = $m[0][0];
            $out .= substr($css, $pos, $at - $pos);
            if ($tok === '/*') {
                $end = strpos($css, '*/', $at + 2);
                if ($end === false) {
                    $out .= substr($css, $at);
                    $pos = $len;
                    break;
                }
                $end += 2;
                if (($css[$at + 2] ?? '') === '!') {
                    $out .= substr($css, $at, $end - $at);
                } else {
                    $end = $this->dropLineIfEmpty($out, $css, $end);
                }
                $pos = $end;
            } elseif ($tok === '"' || $tok === "'") {
                // рядок до тієї ж лапки без екранування; CSS-рядок без кінця — до кінця рядка (так його читає й браузер)
                if (!preg_match('~\G' . $tok . '(?:[^' . $tok . '\\\\\n]|\\\\.)*(?:' . $tok . '|(?=\n)|$)~s', $css, $s, 0, $at)) {
                    $out .= substr($css, $at);
                    $pos = $len;
                    break;
                }
                $out .= $s[0];
                $pos = $at + \strlen($s[0]);
            } else {
                // url( без лапок — до дужки (там може бути що завгодно, зокрема /* у data:); з лапками — як рядок у наступній ітерації
                preg_match('~\Gurl\(\s*(?!["\'])[^)]*\)?|\Gurl\(\s*~i', $css, $u, 0, $at);
                $out .= $u[0];
                $pos = $at + \strlen($u[0]);
            }
        }
        $out .= substr($css, $pos);

        return (string) preg_replace('~(?:\n[ \t]*)+\n~', "\n", $out);
    }

    /**
     * `/* … *\/` і `// …` поза рядками, шаблонними рядками й регулярними виразами. null — розбір непевний, скрипт не чіпати.
     */
    public function js(string $js): ?string
    {
        $out = '';
        $pos = 0;
        $len = \strlen($js);
        /** @var list<int> $template глибина фігурних дужок кожного відкритого `${` шаблонного рядка */
        $template = [];
        $braces = 0;
        while ($pos < $len) {
            $pattern = $template === [] ? '~["\'`/]~' : '~["\'`/{}]~';
            if (!preg_match($pattern, $js, $m, \PREG_OFFSET_CAPTURE, $pos)) {
                break;
            }
            $at = (int) $m[0][1];
            $tok = $m[0][0];
            $out .= substr($js, $pos, $at - $pos);

            if ($tok === '"' || $tok === "'") {
                if (!preg_match('~\G' . $tok . '(?:[^' . $tok . '\\\\\n\r]|\\\\.)*' . $tok . '~s', $js, $s, 0, $at)) {
                    return null;
                }
                $out .= $s[0];
                $pos = $at + \strlen($s[0]);
                continue;
            }
            if ($tok === '`') {
                $out .= '`';
                $pos = $this->templateTail($js, $at + 1, $out, $template, $braces);
                if ($pos === null) {
                    return null;
                }
                continue;
            }
            if ($tok === '{') {
                ++$braces;
                $out .= '{';
                $pos = $at + 1;
                continue;
            }
            if ($tok === '}') {
                $out .= '}';
                $pos = $at + 1;
                if ($braces > 0) {
                    --$braces;
                    continue;
                }
                // кінець ${…}: далі знову тіло шаблонного рядка
                $braces = (int) array_pop($template);
                $pos = $this->templateTail($js, $pos, $out, $template, $braces);
                if ($pos === null) {
                    return null;
                }
                continue;
            }

            // '/': блоковий коментар, рядковий коментар, регулярний вираз або ділення
            $next = $js[$at + 1] ?? '';
            if ($next === '*') {
                $end = strpos($js, '*/', $at + 2);
                if ($end === false) {
                    return null;
                }
                $end += 2;
                if (($js[$at + 2] ?? '') === '!') {
                    $out .= substr($js, $at, $end - $at);
                } else {
                    $end = $this->dropLineIfEmpty($out, $js, $end);
                }
                $pos = $end;
                continue;
            }
            if ($next === '/') {
                $end = strpos($js, "\n", $at + 2);
                $end = $end === false ? $len : $end;
                if ($end > $at && $js[$end - 1] === "\r") {
                    --$end;
                }
                $pos = $this->dropLineIfEmpty($out, $js, $end);
                continue;
            }
            if ($this->isRegexStart($out)) {
                if (!preg_match('~\G/(?:[^/\\\\\n\r\[]|\\\\.|\[(?:[^\]\\\\\n\r]|\\\\.)*\])+/[a-zA-Z]*~', $js, $r, 0, $at)) {
                    return null;
                }
                $out .= $r[0];
                $pos = $at + \strlen($r[0]);
                continue;
            }
            $out .= '/';
            $pos = $at + 1;
        }
        if ($template !== []) {
            return null;
        }

        return $out . substr($js, $pos);
    }

    /** Чи <script …> з JavaScript (без type або з одним із JS_TYPES). */
    private function isJavaScript(string $openTag): bool
    {
        if (!preg_match('~\stype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $openTag, $t)) {
            return true;
        }
        $type = strtolower(trim($t[1] !== '' ? $t[1] : ($t[2] !== '' ? $t[2] : ($t[3] ?? ''))));

        return \in_array($type, self::JS_TYPES, true);
    }

    /**
     * Тіло шаблонного рядка від $pos: до закривального ` (повертає позицію за ним) або до `${` (повертає позицію за ним,
     * запамʼятавши глибину дужок — далі код). null — рядок не закрито.
     *
     * @param list<int> $template
     */
    private function templateTail(string $js, int $pos, string &$out, array &$template, int &$braces): ?int
    {
        if (!preg_match('~\G(?:[^`\\\\$]|\\\\.|\$(?!\{))*(`|\$\{)~s', $js, $m, 0, $pos)) {
            return null;
        }
        $out .= $m[0];
        if ($m[1] === '${') {
            $template[] = $braces;
            $braces = 0;
        }

        return $pos + \strlen($m[0]);
    }

    /** Чи `/` після вже виведеного коду починає регулярний вираз (а не ділення). */
    private function isRegexStart(string $out): bool
    {
        $before = rtrim($out);
        if ($before === '') {
            return true;
        }
        $last = $before[\strlen($before) - 1];
        if (str_ends_with($before, '++') || str_ends_with($before, '--')) {
            return false;
        }
        if (str_contains(self::REGEX_PREFIX_CHARS, $last)) {
            return true;
        }
        if ($last === ')' || $last === ']') {
            return false;
        }
        if (preg_match('~[A-Za-z_$][A-Za-z0-9_$]*$~', $before, $w)) {
            return \in_array($w[0], self::REGEX_KEYWORDS, true);
        }

        return false;
    }

    /**
     * Після вирізання коментаря, що закінчується на $end: якщо він стояв сам на рядку — прибрати й рядок (відступ перед,
     * перенос після); якщо був у кінці рядка коду — прибрати пробіли перед ним. Повертає нову позицію читання.
     */
    private function dropLineIfEmpty(string &$out, string $src, int $end): int
    {
        $nl = strrpos($out, "\n");
        $tail = $nl === false ? $out : substr($out, $nl + 1);
        $atLineEnd = ($src[$end] ?? "\n") === "\n" || (($src[$end] ?? '') === "\r" && ($src[$end + 1] ?? '') === "\n");
        if (!$atLineEnd) {
            if (str_ends_with($out, ' ') && (($src[$end] ?? '') === ' ' || ($src[$end] ?? '') === "\t")) {
                $out = rtrim($out, " \t");
            }

            return $end;
        }
        if (trim($tail) === '') {
            $out = $nl === false ? '' : substr($out, 0, $nl + 1);

            return min($end + (($src[$end] ?? '') === "\r" ? 2 : 1), \strlen($src));
        }
        $out = rtrim($out, " \t");

        return $end;
    }
}
