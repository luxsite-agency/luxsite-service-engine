<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\Html\CommentStripper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Чистий HTML (вимога власника 25.10.2026): коментарі розробника зникають, код і текст — ні. Головний ризик — JS-токенайзер,
 * тому для нього окремо рядки, шаблонні рядки, регулярні вирази, URL, ділення й реальні скрипти шаблонів рушія.
 */
final class CommentStripperTest extends TestCase
{
    private CommentStripper $s;

    protected function setUp(): void
    {
        $this->s = new CommentStripper();
    }

    // ---------- HTML ----------

    public function testHtmlCommentsRemovedButConditionalKept(): void
    {
        $html = "<!DOCTYPE html>\n<html>\n<!-- шапка -->\n<head><!--[if IE]><p>IE</p><![endif]--></head>\n<body>\n    <!-- блок -->\n<p>a</p><!--city-raw-->Київ<!--/city-raw--><p>b</p>\n<!--[if !IE]><!--><p>не IE</p><!--<![endif]-->\n</body></html>";
        $out = $this->s->html($html);
        self::assertStringNotContainsString('шапка', $out);
        self::assertStringNotContainsString('блок', $out);
        self::assertStringNotContainsString('city-raw', $out);
        self::assertStringContainsString('<!--[if IE]><p>IE</p><![endif]-->', $out);
        self::assertStringContainsString('<!--[if !IE]><!--><p>не IE</p><!--<![endif]-->', $out);
        self::assertStringContainsString('<p>a</p>Київ<p>b</p>', $out);
        // коментар сам на рядку — зникає з рядком, порожнього рядка не лишається
        self::assertStringContainsString("<html>\n<head>", $out);
        self::assertStringContainsString("<body>\n<p>a</p>", $out);
    }

    public function testPreTextareaCodeAndAttributesUntouched(): void
    {
        $html = '<pre><!-- лишити --> /* і це */ // і це</pre><textarea><!-- x --></textarea><code>// not a comment</code>'
            . '<a title="<!-- атрибут -->" data-x="/* y */">t</a><p>текст /* не код */ і // не коментар</p>';
        self::assertSame($html, $this->s->html($html));
    }

    public function testJsonLdAndTemplatesUntouchedWhileInlineJsCleaned(): void
    {
        $ld = '<script type="application/ld+json">{"@context":"https://schema.org","url":"https://x/a//b","note":"/* не чіпати */"}</script>';
        $tpl = '<script type="text/template"><!-- шаблон --> // текст</script>';
        $js = "<script>\n    // коментар\n    var a = 1; /* ще */\n    var u = 'https://example.com/x'; // url у рядку\n</script>";
        $mod = "<script type=\"module\">// m\nimport x from './x.js';</script>";
        $out = $this->s->html($ld . $tpl . $js . $mod);
        self::assertStringContainsString($ld, $out);
        self::assertStringContainsString($tpl, $out);
        self::assertStringContainsString("<script>\n    var a = 1;\n    var u = 'https://example.com/x';\n</script>", $out);
        self::assertStringContainsString("<script type=\"module\">import x from './x.js';</script>", $out);
    }

    public function testStyleCleanedAndNoscriptAsHtml(): void
    {
        $html = "<style>\n/* тема */\nbody { color: red; /* акцент */ }\n\n.x::after { content: \"/* не коментар */\"; }\n</style><noscript><!-- без js --><img src=\"x\"></noscript>";
        $out = $this->s->html($html);
        self::assertSame("<style>\nbody { color: red; }\n.x::after { content: \"/* не коментар */\"; }\n</style><noscript><img src=\"x\"></noscript>", $out);
    }

    public function testUnclosedCommentOrTagLeftAsIs(): void
    {
        self::assertSame('<p>a</p><!-- без кінця', $this->s->html('<p>a</p><!-- без кінця'));
        self::assertSame('<p>a</p><script>var x = 1; // x', $this->s->html('<p>a</p><script>var x = 1; // x'));
    }

    // ---------- CSS ----------

    public function testCss(): void
    {
        $css = "/* шапка */\n:root { --a: #fff; } /* кінець рядка */\n\n\n.a { background: url(data:image/svg+xml;utf8,/*x*/) no-repeat; }\n.b { background: url(\"/img/a.png\") /* після url */; }\n.c::before { content: '/* не */'; content: \"a'b /* теж не */\"; }\n/*! ліцензія */\n.d { /* внутрішній\n   багаторядковий */ color: red; }\n";
        $want = ":root { --a: #fff; }\n.a { background: url(data:image/svg+xml;utf8,/*x*/) no-repeat; }\n.b { background: url(\"/img/a.png\") ; }\n.c::before { content: '/* не */'; content: \"a'b /* теж не */\"; }\n/*! ліцензія */\n.d { color: red; }\n";
        self::assertSame($want, $this->s->css($css));
        self::assertSame('.a { color: red; } /* без кінця', $this->s->css('.a { color: red; } /* без кінця'));
    }

    // ---------- JS ----------

    /** @return iterable<string, array{string, string}> */
    public static function jsCases(): iterable
    {
        yield 'рядки з // і /*' => ["var a = 'http://x//y'; var b = \"/* no */\"; // так\n", "var a = 'http://x//y'; var b = \"/* no */\";\n"];
        yield 'екранована лапка в рядку' => ["var s = 'it\\'s // fine'; // c\nvar t = \"a\\\"b /* z */\";", "var s = 'it\\'s // fine';\nvar t = \"a\\\"b /* z */\";"];
        yield 'шаблонний рядок з ${} і //' => ["var u = `https://x/\${a /* div */}/y`; // c\n", "var u = `https://x/\${a }/y`;\n"];
        yield 'шаблонний рядок багаторядковий із порожніми рядками' => ["var t = `a\n\n// не коментар\n/* не */\n`;\n// c\n", "var t = `a\n\n// не коментар\n/* не */\n`;\n"];
        yield 'вкладені дужки в ${}' => ["var t = `x\${ f({a: 1}) }y`; // c", 'var t = `x${ f({a: 1}) }y`;'];
        yield 'regex після (' => ["if (/(?:^|;\\s*)sv_consent=/.test(document.cookie)) { return; } // c\n", "if (/(?:^|;\\s*)sv_consent=/.test(document.cookie)) { return; }\n"];
        yield 'regex із // усередині й класом символів' => ["var r = /[/]\\/\\/[^/]+\\/\\//g; /* c */\nvar q = s.replace(/\\B(?=(\\d{3})+(?!\\d))/g, ' ');", "var r = /[/]\\/\\/[^/]+\\/\\//g;\nvar q = s.replace(/\\B(?=(\\d{3})+(?!\\d))/g, ' ');"];
        yield 'regex після return і =>' => ["function f() { return /a\\/\\/b/.test(x); } var g = () => /\\/\\*x/; // c", 'function f() { return /a\/\/b/.test(x); } var g = () => /\/\*x/;'];
        yield 'ділення після ідентифікатора, числа, ) і ]' => ["var a = b / c; var d = 10 / 2; var e = (x) / y[0] / 2; // c\nvar f = i++ / 2;", "var a = b / c; var d = 10 / 2; var e = (x) / y[0] / 2;\nvar f = i++ / 2;"];
        yield 'ділення й коментар на одному рядку' => ["a = b / c; // половина\nd = e / f; /* блок */ g = 1;", "a = b / c;\nd = e / f; g = 1;"];
        yield 'блоковий коментар сам на рядку й багаторядковий' => ["(function () {\n    /* перший\n       другий */\n    var a = 1;\n    // рядковий\n    var b = 2; // хвіст\n})();", "(function () {\n    var a = 1;\n    var b = 2;\n})();"];
        yield 'ліцензія /*! лишається' => ["/*! lib v1 | MIT */\nvar x = 1; /* c */", "/*! lib v1 | MIT */\nvar x = 1;"];
        yield 'URL без лапок не буває, але http: у коді — ділення, не коментар' => ["var p = location.protocol === 'https:' ? ';Secure' : ''; // c", "var p = location.protocol === 'https:' ? ';Secure' : '';"];
        yield 'коментар у кінці без переносу' => ['var a = 1; // кінець', 'var a = 1;'];
        yield 'лише коментар' => ["// нічого\n", ''];
        yield 'CRLF' => ["var a = 1; // c\r\n// d\r\nvar b = 2;", "var a = 1;\r\nvar b = 2;"];
        yield 'GTM-сніпет' => ["(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-X');", "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-X');"];
    }

    #[DataProvider('jsCases')]
    public function testJs(string $src, string $want): void
    {
        self::assertSame($want, $this->s->js($src));
    }

    /** @return iterable<string, array{string}> */
    public static function jsUnsureCases(): iterable
    {
        yield 'незакритий рядок' => ["var a = 'x; // c\n"];
        yield 'незакритий шаблонний рядок' => ['var a = `x; // c'];
        yield 'незакритий блоковий коментар' => ['var a = 1; /* c'];
                yield 'незакрите ${' => ['var a = `x${y`;'];
    }

    #[DataProvider('jsUnsureCases')]
    public function testJsUnsureReturnsNull(string $src): void
    {
        self::assertNull($this->s->js($src));
    }

    public function testRegexWithoutEndIsLeftUnchanged(): void
    {
        // `/abc; /` читається як regex, далі ділення: нічого не вирізано — коментар лишився, код цілий
        $src = "var a = /abc; // c\n";
        self::assertContains($this->s->js($src), [null, $src]);
    }

    public function testUnsureInlineScriptLeftUntouchedInHtml(): void
    {
        $html = "<p>x</p><script>var a = 'x; // c\n</script><!-- h -->";
        self::assertSame("<p>x</p><script>var a = 'x; // c\n</script>", $this->s->html($html));
    }

    /**
     * Реальні інлайн-скрипти рушія (templates/services): після чистки — без коментарів, а всі рядки, URL і regex лишаються.
     * Twig-теги замінюються на заглушки, щоб скрипт лишався схожим на те, що бачить браузер.
     */
    public function testRealEngineScripts(): void
    {
        $dir = \dirname(__DIR__, 2) . '/templates/services';
        $files = array_merge(glob($dir . '/*.twig') ?: [], glob($dir . '/*/*.twig') ?: []);
        $scripts = 0;
        foreach ($files as $file) {
            $twig = (string) file_get_contents($file);
            $twig = (string) preg_replace('~\{#.*?#\}~s', '', $twig);
            $twig = (string) preg_replace('~\{\{.*?\}\}~s', '"TWIG"', $twig);
            $twig = (string) preg_replace('~\{%.*?%\}~s', '', $twig);
            preg_match_all('~<script(?![^>]*type=)[^>]*>(.*?)</script>~is', $twig, $m);
            foreach ($m[1] as $src) {
                if (trim($src) === '') {
                    continue;
                }
                ++$scripts;
                $out = $this->s->js($src);
                self::assertNotNull($out, basename($file) . ': токенайзер впевнений');
                self::assertDoesNotMatchRegularExpression('~^\s*//~m', $out, basename($file) . ': без // коментарів');
                self::assertStringNotContainsString('/*', $out, basename($file) . ': без /* коментарів');
                // усе, що в лапках, регулярні вирази й ключові виклики лишаються
                preg_match_all('~\'[^\'\n]*\'|"[^"\n]*"~', $src, $strings);
                foreach ($strings[0] as $str) {
                    self::assertStringContainsString($str, $out, basename($file) . ': рядок ' . $str);
                }
                foreach (['addEventListener(', 'querySelector(', 'dataLayer.push(', 'JSON.parse(', 'getItem(', 'replace(/\B'] as $needle) {
                    if (str_contains($src, $needle)) {
                        self::assertSame(substr_count($src, $needle), substr_count($out, $needle), basename($file) . ': ' . $needle);
                    }
                }
            }
        }
        self::assertGreaterThan(8, $scripts, 'знайдено інлайн-скрипти рушія');
    }

    public function testRealEngineStyles(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/services/base.html.twig');
        preg_match('~<style>(.*?)</style>~s', $css, $m);
        $src = (string) preg_replace('~\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}~s', 'x', $m[1]);
        self::assertGreaterThan(20, substr_count($src, '/*'), 'у стилях рушія є коментарі');
        $out = $this->s->css($src);
        self::assertStringNotContainsString('/*', $out);
        self::assertDoesNotMatchRegularExpression('~\n[ \t]*\n~', $out, 'порожні рядки схлопнуто');
        self::assertSame(substr_count($src, '{'), substr_count($out, '{'));
        self::assertSame(substr_count($src, '}'), substr_count($out, '}'));
    }

    public function testPerformanceOnLargePage(): void
    {
        $block = "<p>Текст сторінки</p><!-- c -->\n<style>\n/* a */\n.x { color: red; } /* b */\n</style>\n<script>\n// c\nvar a = 'https://x/y'; /* d */\nvar r = /a\\/b/g;\n</script>\n";
        $html = str_repeat($block, 780); // ≈130 КБ
        self::assertGreaterThan(120_000, \strlen($html));
        $t = hrtime(true);
        $out = $this->s->html($html);
        $ms = (hrtime(true) - $t) / 1e6;
        self::assertStringNotContainsString('<!--', $out);
        self::assertLessThan(50, $ms, 'один прохід без DOM: 130 КБ за одиниці мс (тут ' . round($ms, 1) . ' мс, запас на повільний CI)');
    }
}
