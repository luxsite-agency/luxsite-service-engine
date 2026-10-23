<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\Redirect\RedirectPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Нормалізація адрес менеджера редиректів (перенесено з shop-engine разом із кодом). */
final class RedirectPathTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function sources(): iterable
    {
        yield 'як є' => ['/services/electrician/', '/services/electrician/'];
        yield 'без кінцевого слеша зберігається' => ['/price.html', '/price.html'];
        yield 'регістр' => ['/Services/ELECTRICIAN/', '/services/electrician/'];
        yield 'query і фрагмент' => ['/services/x/?utm_source=x&id=1#top', '/services/x/'];
        yield 'повний URL' => ['https://old-site.com.ua/Catalog/Item.html?x=1', '/catalog/item.html'];
        yield 'URL без шляху' => ['https://old-site.com.ua', '/'];
        yield 'протокол-відносний' => ['//old-site.com.ua/a', '/a'];
        yield 'без провідного слеша' => ['ru/services/x/', '/ru/services/x/'];
        yield '«//» на початку — хост, як у браузері' => ['//services///x/', '/x/'];
        yield '%-кодування кирилиці' => ['/%D0%9F%D0%BE%D1%81%D0%BB%D1%83%D0%B3%D0%B8/', '/послуги/'];
        yield 'пробіли довкола' => ["  /old/ \n", '/old/'];
        yield 'порожньо' => ['', null];
        yield 'лише query' => ['?a=1', null];
        yield 'керівний символ' => ['/a%00b', null];
        yield 'невалідний UTF-8' => ['/%FF%FE', null];
        yield 'задовге' => ['/' . str_repeat('a', RedirectPath::MAX_LENGTH), null];
    }

    #[DataProvider('sources')]
    public function testNormalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, RedirectPath::normalize($input));
    }

    public function testRequestPathWithLeadingDoubleSlashIsNotAUrl(): void
    {
        self::assertSame('/services/x/', RedirectPath::fromRequest('//Services//x/'));
    }

    public function testRepeatedSlashesInsidePathCollapse(): void
    {
        self::assertSame('/services/x/', RedirectPath::normalize('/services///x/'));
    }

    public function testTarget(): void
    {
        self::assertSame('/Files/Price.PDF', RedirectPath::normalizeTarget('/Files//Price.PDF'), 'регістр цілі як є (статичні файли), слеші — один');
        self::assertSame('/Search?q=Shoes', RedirectPath::normalizeTarget('/Search?q=Shoes'), 'query цілі зберігається як є');
        self::assertSame('/a', RedirectPath::normalizeTarget('/a#frag?x=1'), 'query після фрагмента — частина фрагмента');
        self::assertNull(RedirectPath::normalizeTarget("/a?x=1\r\nSet-Cookie: s=1"), 'CR/LF у query — ні');
        self::assertNull(RedirectPath::normalizeTarget('/a?x=%0d%0aSet-Cookie'), 'закодовані CR/LF — теж');
        self::assertNull(RedirectPath::normalizeTarget("https://a.example/\nx"));
        self::assertSame('/a', RedirectPath::normalizeTarget('/a?'), 'порожній query — геть');
        self::assertSame('https://Other.example/Path?x=1', RedirectPath::normalizeTarget(' https://Other.example/Path?x=1 '), 'інший сайт — без змін');
        self::assertNull(RedirectPath::normalizeTarget('nova'), 'відносна адреса');
        self::assertNull(RedirectPath::normalizeTarget('//evil.example/x'), 'протокол-відносна — ні');
        self::assertNull(RedirectPath::normalizeTarget('javascript:alert(1)'));
        self::assertNull(RedirectPath::normalizeTarget('https://a.example/with space'));
    }

    public function testTargetPathOnlyForChainableTargets(): void
    {
        self::assertSame('/nova/', RedirectPath::targetPath('/nova/'));
        self::assertSame('/nova/', RedirectPath::targetPath('/Nova/'), 'у формі джерела');
        self::assertNull(RedirectPath::targetPath('/search?q=x'));
        self::assertNull(RedirectPath::targetPath('https://other.example/nova'));
    }

    public function testOwnHostTargetBecomesPath(): void
    {
        self::assertSame('/b/?x=1', RedirectPath::localize('https://Kyiv.Example.ua/b/?x=1', 'example.ua', 'kyiv.example.ua'));
        self::assertSame('/', RedirectPath::localize('https://www.example.ua', 'example.ua'));
        self::assertSame('https://other.example/b', RedirectPath::localize('https://other.example/b', 'example.ua'));
        self::assertSame('/b', RedirectPath::localize('/b', 'example.ua'));
    }

    public function testEncodeForLocationHeader(): void
    {
        self::assertSame('/%D0%BF%D0%BE%D1%81%D0%BB%D1%83%D0%B3%D0%B8/a%20b?q=1', RedirectPath::encode('/послуги/a b?q=1'));
        self::assertSame('/services/x/', RedirectPath::encode('/services/x/'));
    }
}
