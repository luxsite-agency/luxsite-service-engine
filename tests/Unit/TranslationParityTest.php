<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Переклади сайту servicesite.{uk,ru,en}.yaml мають однакові ключі; en — ще й ті самі параметри (%site%, %city%…) і порожні значення, що uk.
 * Глобальний фолбек перекладача — uk, тож ключ, якого бракує в en, показав би англійському сайту український текст.
 */
final class TranslationParityTest extends TestCase
{
    public function testSameKeysAndPlaceholders(): void
    {
        $dir = \dirname(__DIR__, 2) . '/translations';
        $uk = $this->flat(Yaml::parseFile($dir . '/servicesite.uk.yaml'));
        foreach (['ru', 'en'] as $locale) {
            $other = $this->flat(Yaml::parseFile($dir . '/servicesite.' . $locale . '.yaml'));
            self::assertSame([], array_values(array_diff(array_keys($uk), array_keys($other))), "ключі, яких бракує в $locale");
            self::assertSame([], array_values(array_diff(array_keys($other), array_keys($uk))), "зайві ключі в $locale");
            if ($locale === 'ru') {
                continue; // ru історично має свої формулювання (напр. без %in%) — для нього лише ключі
            }
            foreach ($uk as $key => $value) {
                self::assertSame($this->params($value), $this->params($other[$key]), "параметри ключа $key ($locale)");
                self::assertSame($value === '', $other[$key] === '', "порожнє значення ключа $key ($locale) = елемент не показується");
            }
        }
    }

    /** @return array<string, string> */
    private function flat(mixed $data, string $prefix = ''): array
    {
        $out = [];
        foreach ((array) $data as $k => $v) {
            if (\is_array($v)) {
                $out += $this->flat($v, $prefix . $k . '.');
            } else {
                $out[$prefix . $k] = (string) $v;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function params(string $value): array
    {
        preg_match_all('/%[A-Za-z_]+%/', $value, $m);
        $p = array_values(array_unique($m[0]));
        sort($p);

        return $p;
    }
}
