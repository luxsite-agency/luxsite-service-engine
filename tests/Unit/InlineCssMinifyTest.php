<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\SiteTheme;
use PHPUnit\Framework\TestCase;

/** Вбудований CSS теми (stylesheet_inline): без коментарів і зайвих пробілів, селектори й значення не змінюються (25.10.2026). */
final class InlineCssMinifyTest extends TestCase
{
    public function testCommentsAndWhitespaceRemoved(): void
    {
        $css = <<<'CSS'
            /* Новинний дизайн 7 брендів — згенеровано tools/build-news-themes.py; не правити руками. */
            @font-face { font-family: 'Manrope'; src: url(/themes/x/fonts/a.woff2?v=1) format('woff2'); font-display: swap; }
            :root { --nw-accent: #0B57B7;   --nw-pad: clamp(16px, 3vw, 32px); }
            /* рядок стрічки:
               кілька рядків коментаря */
            .nw-row :is(h2, h3) a { color: var(--nw-ink); }
            @media (max-width: 760px) {
                .nw-row-pic { grid-template-columns: auto minmax(0, 1fr) 98px; } /* у кінці рядка */
            }
            CSS;
        $out = SiteTheme::minifyCss($css);

        self::assertStringNotContainsString('/*', $out);
        self::assertStringNotContainsString('build-news-themes', $out);
        self::assertStringNotContainsString("\n", $out);
        self::assertStringContainsString('.nw-row :is(h2, h3) a{color: var(--nw-ink)}', $out, 'пробіл-комбінатор перед :is лишається');
        self::assertStringContainsString('@media (max-width: 760px){.nw-row-pic{grid-template-columns: auto minmax(0, 1fr) 98px}}', $out);
        self::assertStringContainsString(":root{--nw-accent: #0B57B7;--nw-pad: clamp(16px, 3vw, 32px)}", $out);
        self::assertStringContainsString("src: url(/themes/x/fonts/a.woff2?v=1) format('woff2')", $out);
        self::assertStringStartsWith('@font-face{', $out);
    }

    /** Рядки в лапках — недоторкані; апостроф у коментарі не відкриває рядок; порожня кастомна властивість зберігає пробіл */
    public function testStringsAndEdgeCases(): void
    {
        $css = "/* прев'ю зображень: в'юпорт */ a[href^=\"/*\"] { color: red; } .keep { content: \" ; {x}  y \"; }\n"
            . ".q { font-family: 'Segoe UI', sans-serif; } .t { --on: ; } /* кінець з апострофом ' */ .z { x: 1 }";
        $out = SiteTheme::minifyCss($css);

        self::assertSame("a[href^=\"/*\"]{color: red}.keep{content: \" ; {x}  y \"}.q{font-family: 'Segoe UI', sans-serif}.t{--on: }.z{x: 1}", $out);
    }

    public function testInlineCssUsesMinifier(): void
    {
        $dir = sys_get_temp_dir() . '/inline-css-' . bin2hex(random_bytes(4));
        mkdir($dir . '/public/themes/t', 0777, true);
        file_put_contents($dir . '/public/themes/t/theme.css', "/* згенеровано tools/build.py — не правити */\n.a { b: url(fonts/x.woff2); }\n");
        $theme = new SiteTheme(['stylesheet' => 'themes/t/theme.css', 'stylesheet_inline' => true], $dir);

        self::assertSame('.a{b: url(/themes/t/fonts/x.woff2)}', $theme->inlineCss());
        unlink($dir . '/public/themes/t/theme.css');
        rmdir($dir . '/public/themes/t');
        rmdir($dir . '/public/themes');
        rmdir($dir . '/public');
        rmdir($dir);
    }

    public function testEmptyAndCommentOnly(): void
    {
        self::assertSame('', SiteTheme::minifyCss(''));
        self::assertSame('', SiteTheme::minifyCss("/* лише коментар */\n"));
    }
}
