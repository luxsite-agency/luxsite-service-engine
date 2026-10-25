<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Службові коментарі не потрапляють у HTML (25.10.2026): у вбудованих <style> і <script> публічних шаблонів рушія
 * (templates/services, templates/bundles) — лише Twig {# … #}. Статична перевірка всіх гілок і partials, без HTTP.
 * Адмінка (templates/admin) відвідувачам не віддається — поза перевіркою.
 */
final class TemplateInlineCommentsTest extends TestCase
{
    public function testNoCssOrJsCommentsInInlineBlocks(): void
    {
        $root = \dirname(__DIR__, 2) . '/templates';
        $problems = [];
        $checked = 0;
        foreach (['services', 'bundles'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }
                $src = (string) preg_replace('~\{#.*?#\}~s', '', (string) file_get_contents($file->getPathname()));
                $rel = substr($file->getPathname(), \strlen($root) + 1);
                preg_match_all('~<style[^>]*>(.*?)</style>~si', $src, $styles);
                foreach ($styles[1] as $css) {
                    ++$checked;
                    if (str_contains($css, '/*')) {
                        $problems[] = $rel . ': /* у <style>';
                    }
                }
                preg_match_all('~<script(?![^>]*(?:application/ld\+json|src=))[^>]*>(.*?)</script>~si', $src, $scripts);
                foreach ($scripts[1] as $js) {
                    ++$checked;
                    if (str_contains($js, '/*')) {
                        $problems[] = $rel . ': /* у <script>';
                    }
                    if (preg_match('~(^|[\s;{}])//(?!/)~m', $js)) {
                        $problems[] = $rel . ': // у <script>';
                    }
                }
            }
        }

        self::assertGreaterThan(10, $checked, 'знайдено вбудовані блоки');
        self::assertSame([], $problems, 'коментарі у вбудованих блоках — переносити в Twig {# … #}');
    }
}
