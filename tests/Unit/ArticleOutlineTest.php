<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\ArticleOutline;
use PHPUnit\Framework\TestCase;

/** Зміст статті: H2/H3 у плаский список, ті самі id у тексті, хвилини читання. */
final class ArticleOutlineTest extends TestCase
{
    public function testHeadingsKeepLevelsAndOrder(): void
    {
        $o = new ArticleOutline();
        $html = '<p>Intro</p><h2>What is NVC?</h2><p>a</p><h3>1. Observation</h3><h3>2. Feelings</h3><h2>Where can NVC be applied?</h2><h4>Skipped</h4>';

        self::assertSame([
            ['level' => 2, 'id' => 'what-is-nvc', 'text' => 'What is NVC?'],
            ['level' => 3, 'id' => '1-observation', 'text' => '1. Observation'],
            ['level' => 3, 'id' => '2-feelings', 'text' => '2. Feelings'],
            ['level' => 2, 'id' => 'where-can-nvc-be-applied', 'text' => 'Where can NVC be applied?'],
        ], $o->headings($html), 'H4 у зміст не беремо — у ТЗ лише H2/H3');
    }

    public function testAnchorsMatchHeadingIds(): void
    {
        $o = new ArticleOutline();
        $html = '<h2>Key components</h2><h3>Needs</h3>';
        $out = $o->withAnchors($html);

        foreach ($o->headings($html) as $h) {
            self::assertStringContainsString('id="' . $h['id'] . '"', $out, 'посилання зі змісту мусить знаходити заголовок');
        }
        self::assertSame('<h2 id="key-components">Key components</h2><h3 id="needs">Needs</h3>', $out);
    }

    public function testExistingIdIsKept(): void
    {
        $o = new ArticleOutline();
        $html = '<h2 id="own-anchor" class="x">Heading</h2>';

        self::assertSame([['level' => 2, 'id' => 'own-anchor', 'text' => 'Heading']], $o->headings($html));
        self::assertSame($html, $o->withAnchors($html), 'свій id редактора не переписуємо');
    }

    public function testDuplicateHeadingsGetUniqueIds(): void
    {
        $o = new ArticleOutline();
        $html = '<h2>Feedback</h2><h2>Feedback</h2>';

        self::assertSame(['feedback', 'feedback-2'], array_column($o->headings($html), 'id'));
        self::assertStringContainsString('id="feedback-2"', $o->withAnchors($html));
    }

    public function testNonLatinHeadingsGetLatinIds(): void
    {
        $o = new ArticleOutline();

        self::assertSame('fuehrung-im-wandel', $o->headings('<h2>Führung im Wandel</h2>')[0]['id']);
        self::assertSame('zvorotnyi-zviazok', $o->headings('<h2>Зворотний зв’язок</h2>')[0]['id']);
    }

    public function testMarkupAndEntitiesStrippedFromTitles(): void
    {
        $o = new ArticleOutline();

        self::assertSame('Data &amp; stories', $o->headings('<h2><strong>Data &amp;amp; stories</strong></h2>')[0]['text']);
    }

    public function testReadMinutesRoundUpAndNeverZero(): void
    {
        $o = new ArticleOutline();

        self::assertSame(1, $o->readMinutes('<p>Short text.</p>'));
        self::assertSame(1, $o->readMinutes(null), 'порожня стаття — все одно 1 min read, а не 0');
        self::assertSame(2, $o->readMinutes('<p>' . implode(' ', array_fill(0, 220, 'word')) . '</p>'));
        self::assertSame(2, $o->readMinutes('<p>' . implode(' ', array_fill(0, 120, 'w')) . '</p>', '<p>' . implode(' ', array_fill(0, 120, 'w')) . '</p>'));
    }

    public function testEmptyHtmlGivesNothing(): void
    {
        $o = new ArticleOutline();

        self::assertSame([], $o->headings(null));
        self::assertSame([], $o->headings('   '));
        self::assertSame('', $o->withAnchors(null));
    }
}
