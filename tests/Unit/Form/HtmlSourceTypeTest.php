<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit\Form;

use App\Form\HtmlSourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

final class HtmlSourceTypeTest extends TestCase
{
    /** @return iterable<string, array{?string, string, ?string}> збережене, надіслане браузером, що піде в запис */
    public static function cases(): iterable
    {
        $html = "\n<h2>A</h2>\n<table class=\"t\"><tr><td>1</td></tr></table>\n";
        yield 'не чіпали: CRLF і з’їдений textarea перший перенос' => [$html, "<h2>A</h2>\r\n<table class=\"t\"><tr><td>1</td></tr></table>\r\n", $html];
        yield 'не чіпали: пробіли по краях' => ["  <p>x</p>  ", '<p>x</p>', "  <p>x</p>  "];
        yield 'порожнє лишається null' => [null, "\r\n", null];
        yield 'змінили: збережено як введено, LF' => ['<p>old</p>', "<h3 class=\"x\">new</h3>\r\n<ol><li>1</li></ol>\r\n", "<h3 class=\"x\">new</h3>\n<ol><li>1</li></ol>\n"];
        yield 'очистили' => ['<p>old</p>', '', null];
    }

    #[DataProvider('cases')]
    public function testSubmit(?string $stored, string $submitted, ?string $expected): void
    {
        $form = Forms::createFormFactory()->create(HtmlSourceType::class, $stored);
        $form->submit($submitted);
        self::assertTrue($form->isSynchronized());
        self::assertSame($expected, $form->getData());
    }
}
