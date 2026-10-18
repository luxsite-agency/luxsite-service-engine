<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Command\JoomlaImportCommand;
use App\ServiceSite\Import\JoomlaMapping;
use PHPUnit\Framework\TestCase;

/**
 * Чистка HTML при імпорті з Joomla/Seblod (JoomlaImportCommand::html).
 * Тест закріплює саме цю ділянку: одного разу в регекс затік керівний байт (\b із heredoc),
 * правило мовчки перестало спрацьовувати — і 70 сторінок стенда поїхали версткою.
 */
final class JoomlaImportHtmlTest extends TestCase
{
    private function html(string $in): string
    {
        $file = tempnam(sys_get_temp_dir(), 'jm') . '.yaml';
        file_put_contents($file, <<<'YAML'
            joomla_import:
                site: { slug: s, name: S, apex_domain: s.ua }
                cities: [{ legacy_id: 1, site_id: 1, slug: kyiv, host: s.ua, apex: true }]
                categories: { 1: { slug_uk: a } }
            YAML);
        $cmd = (new \ReflectionClass(JoomlaImportCommand::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(JoomlaImportCommand::class, 'map'))->setValue($cmd, JoomlaMapping::load($file));
        @unlink($file);

        return (string) (new \ReflectionMethod(JoomlaImportCommand::class, 'html'))->invoke($cmd, $in);
    }

    public function testBlockInsideParagraphIsUnwrapped(): void
    {
        self::assertSame(
            "<section>\n<h2>Заголовок</h2>\n</section><p>Текст</p>",
            $this->html("<p><section>\n<h2>Заголовок</h2>\n</section></p><p>Текст</p>"),
        );
        self::assertSame('<h2>Заголовок</h2>', $this->html('<p><h2>Заголовок</h2></p>'));
        self::assertSame('<ul><li>а</li></ul>', $this->html('<p><ul><li>а</li></ul></p>'));
    }

    /** Обгортку з вкладеним однойменним тегом правило свідомо не чіпає — краще лишити як є, ніж склеїти чуже. */
    public function testNestedSameTagIsLeftAlone(): void
    {
        self::assertSame('<p><div><div>а</div></div></p>', $this->html('<p><div><div>а</div></div></p>'));
    }

    public function testLegitimateParagraphSurvives(): void
    {
        self::assertSame('<p>Текст з <a href="/x">лінком</a>.</p>', $this->html('<p>Текст з <a href="/x">лінком</a>.</p>'));
        self::assertSame("<p>Абзац</p>\n<h2>Заголовок</h2>", $this->html("<p>Абзац</p>\n<h2>Заголовок</h2>"));
    }

    public function testEmptyParagraphsAreDropped(): void
    {
        self::assertSame('<h2>А</h2>', $this->html('<p>&nbsp;</p><h2>А</h2><p><br /></p>'));
    }
}
