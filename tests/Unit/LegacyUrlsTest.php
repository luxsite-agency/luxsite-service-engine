<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\ServiceCityRepository;
use App\Repository\ServiceItemRepository;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\LegacyUrls;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LegacyUrlsTest extends TestCase
{
    private function legacy(array $redirects, array $clean = []): LegacyUrls
    {
        return new LegacyUrls(
            $this->createStub(ServiceItemRepository::class),
            $this->createStub(ServiceCityRepository::class),
            new CatalogUrls('root'),
            $this->createStub(UrlGeneratorInterface::class),
            $redirects,
            $clean,
        );
    }

    public function testExactRuleWinsAndPrefixRulesAreOffByDefault(): void
    {
        $req = Request::create('https://l.ua/x/');
        $l = $this->legacy(['cargo-types/old' => '/building-materials/']);
        self::assertSame('https://l.ua/building-materials/', $l->legacyTarget($req, '/cargo-types/old/'));
        self::assertNull($l->legacyTarget($req, '/uk/about/'));
    }

    public function testPrefixRedirectKeepsTailAndResolvesChainInOneStep(): void
    {
        $req = Request::create('https://l.ua/x/');
        $l = $this->legacy(['cargo-types/old' => '/building-materials/'], ['prefix_redirects' => ['/uk/' => '/']]);
        self::assertSame('https://l.ua/about/', $l->legacyTarget($req, '/uk/about/'));
        self::assertSame('https://l.ua/', $l->legacyTarget($req, '/uk/'));
        // /uk/<старий шлях> → одразу кінцева адреса, без 301 → 301
        self::assertSame('https://l.ua/building-materials/', $l->legacyTarget($req, '/uk/cargo-types/old/'));
        self::assertNull($l->legacyTarget($req, '/ukraine/'));
    }

    public function testPrefixRedirectAddsTrailingSlashInOneStep(): void
    {
        $req = Request::create('https://l.ua/x/');
        $l = $this->legacy([], ['prefix_redirects' => ['/uk/' => '/']]);
        // /uk/foo → одразу /foo/ (а не /foo → /foo/)
        self::assertSame('https://l.ua/foo/', $l->legacyTarget($req, '/uk/foo'));
        self::assertSame('https://l.ua/foo/bar/', $l->legacyTarget($req, '/uk/foo/bar'));
        self::assertSame('https://l.ua/sitemap.xml', $l->legacyTarget($req, '/uk/sitemap.xml'));
        // мапа має пріоритет над префіксом і після префікса застосовується один раз
        $l = $this->legacy(['uk/special' => '/special-page/', 'old' => '/new/'], ['prefix_redirects' => ['/uk/' => '/']]);
        self::assertSame('https://l.ua/special-page/', $l->legacyTarget($req, '/uk/special/'));
        self::assertSame('https://l.ua/new/', $l->legacyTarget($req, '/uk/old'));
        self::assertNull($l->legacyTarget($req, '/other/'));
    }

    public function testQueryForKeepsTrackingAndStripsJoomlaParamsOnContentPages(): void
    {
        $l = $this->legacy([], ['prefix_redirects' => ['/uk/' => '/']]);
        $req = Request::create('https://l.ua/uk/cargo?Itemid=12&utm_source=g&gclid=1');
        $to = $l->legacyTarget($req, $req->getPathInfo());
        self::assertSame('https://l.ua/cargo/', $to);
        // root: /cargo/ — сторінка каталогу, ?Itemid= зрізається, трекінг лишається
        self::assertSame('?utm_source=g&gclid=1', $l->queryFor($req, '/cargo/'));
        // поза сторінками контенту query не чіпаємо
        self::assertSame('?Itemid=12&utm_source=g&gclid=1', $l->queryFor($req, '/about/'));
        self::assertSame('', $l->queryFor(Request::create('https://l.ua/uk/cargo'), '/cargo/'));
        self::assertSame('', $l->queryFor(Request::create('https://l.ua/uk/cargo?Itemid=1'), '/cargo/'));
        // cleanQueryTarget — та сама логіка: null, якщо нічого зрізати
        self::assertNull($l->cleanQueryTarget(Request::create('https://l.ua/cargo/?utm_source=g'), '/cargo/'));
        self::assertSame('https://l.ua/cargo/?utm_source=g', $l->cleanQueryTarget(Request::create('https://l.ua/cargo/?utm_source=g&task=no'), '/cargo/'));
    }

    public function testDefaultStripQueryPathsFollowsSchemeAndBlogPrefix(): void
    {
        $root = $this->legacy([]);
        $re = $root->defaultStripQueryPaths();
        foreach (['/cargo/', '/cargo/item/', '/ru/cargo/', '/services/', '/districts/x/', '/blog/post/'] as $p) {
            self::assertMatchesRegularExpression($re, $p, $p);
        }
        foreach (['/', '/about/', '/ru/about/', '/faq/', '/thank-you/', '/calculator-cleaning/', '/api/x', '/sitemap.xml'] as $p) {
            self::assertDoesNotMatchRegularExpression($re, $p, $p);
        }
        $nested = new LegacyUrls(
            $this->createStub(ServiceItemRepository::class),
            $this->createStub(ServiceCityRepository::class),
            new CatalogUrls('nested', 'news'),
            $this->createStub(UrlGeneratorInterface::class),
        );
        $re = $nested->defaultStripQueryPaths();
        self::assertMatchesRegularExpression($re, '/news/post/');
        self::assertMatchesRegularExpression($re, '/ru/services/a/b/');
        self::assertDoesNotMatchRegularExpression($re, '/cargo/');
        self::assertDoesNotMatchRegularExpression($re, '/blog/post/');
        // пак перекриває дефолт
        $own = $this->legacy([], ['strip_query_paths' => '#^/only/#']);
        self::assertNull($own->cleanQueryTarget(Request::create('https://l.ua/cargo/?task=no'), '/cargo/'));
        self::assertSame('https://l.ua/only/x/', $own->cleanQueryTarget(Request::create('https://l.ua/only/x/?task=no'), '/only/x/'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPrefixRules')]
    public function testPrefixRedirectsAreValidatedInConstructor(array $rules, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->legacy([], ['prefix_redirects' => $rules]);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function badPrefixRules(): iterable
    {
        yield 'from без слеша' => [['uk/' => '/'], 'має бути шляхом з «/»'];
        yield 'from корінь' => [['/' => '/x/'], 'не коренем'];
        yield 'to без слеша' => [['/uk/' => 'x/'], 'має починатися з «/»'];
        yield 'to починається з from' => [['/uk/' => '/uk/x/'], 'сам на себе'];
        yield 'цикл двох правил' => [['/a/' => '/b/', '/b/' => '/a/'], 'замикаються в цикл'];
        yield 'цикл трьох правил' => [['/a/' => '/b/', '/b/' => '/c/', '/c/' => '/a/'], 'замикаються в цикл'];
    }

    public function testPrefixChainWithoutCycleIsAllowedAndAppliedOnce(): void
    {
        // /a/ → /b/ і /b/ → /c/: конфіг допустимий, але правило застосовується один раз — без рекурсії
        $l = $this->legacy([], ['prefix_redirects' => ['/a/' => '/b/', '/b/' => '/c/']]);
        self::assertSame('https://l.ua/b/x/', $l->legacyTarget(Request::create('https://l.ua/'), '/a/x/'));
    }

    public function testRootSchemeIsNested(): void
    {
        self::assertTrue((new CatalogUrls('root'))->isNested());
        self::assertTrue((new CatalogUrls('root'))->isRoot());
        self::assertFalse((new CatalogUrls('nested'))->isRoot());
        self::assertFalse((new CatalogUrls('flat'))->isNested());
    }
}
