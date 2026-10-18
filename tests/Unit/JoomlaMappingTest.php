<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\ServiceSite\Import\JoomlaMapping;
use App\ServiceSite\Import\SeblodMarkers;
use PHPUnit\Framework\TestCase;

final class JoomlaMappingTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function load(string $yaml): JoomlaMapping
    {
        $file = tempnam(sys_get_temp_dir(), 'jm') . '.yaml';
        file_put_contents($file, $yaml);
        $this->files[] = $file;

        return JoomlaMapping::load($file);
    }

    public function testLegacyMappingKeepsFirstSiteDefaults(): void
    {
        $m = $this->load(<<<'YAML'
            joomla_import:
                site: { slug: s, name: S, apex_domain: s.ua }
                cities: [{ legacy_id: 920, site_id: 506, slug: kyiv, host: s.ua, apex: true }]
                categories: { 66: { slug_uk: a } }
            YAML);
        $types = $m->itemTypes();
        self::assertCount(1, $types);
        self::assertSame('catalog', $types[0]['type']);
        self::assertSame('item_content', $types[0]['slug']);
        self::assertSame('catid', $types[0]['category']);
        self::assertSame('metatitle', $types[0]['fields']['meta_title']);
        self::assertSame('desc_2', $types[0]['fields']['desc2']);
        self::assertArrayNotHasKey('name', $types[0]['fields']);
        self::assertSame('iblog', $m->posts()['type']);
        self::assertSame(['table' => 'apl_review', 'city' => 'legacy'], $m->reviews());
        self::assertNull($m->teamTable());
        self::assertNull($m->categorySource());
        self::assertNull($m->categoryLanding(66));
        self::assertSame(920, $m->cities()[0]['legacy_id']);
    }

    public function testMultiTypeMappingWithMappedCity(): void
    {
        $m = $this->load(<<<'YAML'
            joomla_import:
                site: { slug: l, name: L, apex_domain: l.ua }
                cities: [{ slug: ukraine, host: l.ua, apex: true, name_nom_uk: Україна, phone: '+380', viber: '380' }]
                category_defaults: { landing: true }
                categories: { 177: { slug_uk: international-transport }, 56: { slug_uk: v, landing: false } }
                category_source: { type: cargo_category }
                items:
                    - { type: cargo, slug: content_alias, fields: { meta_title: meta_title, name: name, desc2: null } }
                    - type: catalog
                      category: 56
                      fields: { name: { uk: 'Catalog subname uk', ru: catalog_subname_ru } }
                      extra: { tonnage: { value: catalog_tonage } }
                posts: { type: inews, fields: { meta_title: meta_title } }
                pages: { about: 1609, home: 2354, categories: { 177: 2368 } }
                reviews: { city: location }
                team: { table: apl_team }
            YAML);
        $city = $m->cities()[0];
        self::assertNull($city['legacy_id']);
        self::assertSame(['name_nom_uk' => 'Україна', 'phone' => '+380', 'viber' => '380'], $city['fields']);
        [$cargo, $catalog] = $m->itemTypes();
        self::assertSame('content_alias', $cargo['slug']);
        self::assertSame('meta_title', $cargo['fields']['meta_title']);
        self::assertArrayNotHasKey('desc2', $cargo['fields']);
        self::assertSame(56, $catalog['category']);
        self::assertSame(['uk' => 'Catalog subname uk', 'ru' => 'catalog_subname_ru'], $catalog['fields']['name']);
        self::assertSame(['tonnage' => ['value' => 'catalog_tonage']], $catalog['extra']);
        self::assertSame('cargo_category', $m->categorySource()['type']);
        self::assertSame('meta_description', $m->categorySource()['fields']['meta_desc']);
        self::assertSame(['about' => 1609, 'home' => 2354], $m->pages());
        self::assertSame([177 => 2368], $m->pageCategories());
        self::assertTrue($m->categoryLanding(177));
        self::assertFalse($m->categoryLanding(56));
        self::assertSame('inews', $m->posts()['type']);
        self::assertSame('location', $m->reviews()['city']);
        self::assertSame('apl_team', $m->teamTable());
        self::assertFalse($m->demoteH1());
    }

    public function testDemoteH1Option(): void
    {
        $m = $this->load(<<<'YAML'
            joomla_import:
                site: { slug: l, name: L, apex_domain: l.ua }
                cities: [{ slug: u, host: l.ua }]
                categories: { 1: { slug_uk: a } }
                texts: { demote_h1: true }
                pages: { home: 2354, home_text: text3 }
            YAML);
        self::assertTrue($m->demoteH1());
        self::assertSame('text3', $m->homeTextField());
        self::assertSame(['home' => 2354], $m->pages(), 'home_text — не сторінка');
    }

    public function testUnknownItemFieldIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->load(<<<'YAML'
            joomla_import:
                site: { slug: l, name: L, apex_domain: l.ua }
                cities: [{ slug: u, host: l.ua }]
                categories: { 1: { slug_uk: a } }
                items: [{ type: cargo, fields: { bogus: x } }]
            YAML)->itemTypes();
    }

    /** @return iterable<string, array{string}> */
    public static function badIdentifiers(): iterable
    {
        yield 'source.prefix з крапкою' => ['source: { prefix: "aim_.x" }'];
        yield 'source.schema з лапкою' => ['source: { schema: "joomla`; DROP" }'];
        yield 'reviews.table з дефісом' => ['reviews: { table: apl-review }'];
        yield 'team.table з пробілом' => ['team: { table: "apl team" }'];
        yield 'category_source.type з крапкою' => ['category_source: { type: cargo.cat }'];
        yield 'posts.type великими' => ['posts: { type: INews }'];
        yield 'items[].type з дефісом' => ['items: [{ type: cargo-auto }]'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIdentifiers')]
    public function testSqlIdentifiersAreValidatedOnLoad(string $yamlLine): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[a-z0-9_]');
        $this->load(<<<YAML
            joomla_import:
                site: { slug: l, name: L, apex_domain: l.ua }
                cities: [{ slug: u, host: l.ua }]
                categories: { 1: { slug_uk: a } }
                {$yamlLine}
            YAML);
    }

    public function testValidIdentifiersPass(): void
    {
        $m = $this->load(<<<'YAML'
            joomla_import:
                site: { slug: l, name: L, apex_domain: l.ua }
                cities: [{ slug: u, host: l.ua }]
                categories: { 1: { slug_uk: a } }
                source: { schema: joomla_src2, prefix: ls_ }
                reviews: { table: apl_review2, city: location }
                team: { table: apl_team }
                category_source: { type: cargo_category }
                posts: { type: inews }
                items: [{ type: cargo_auto }]
            YAML);
        self::assertSame('joomla_src2', $m->sourceSchema());
        self::assertSame('ls_', $m->tablePrefix());
        self::assertSame('apl_team', $m->teamTable());
        self::assertSame('cargo_category', $m->categorySource()['type']);
        self::assertSame('cargo_auto', $m->itemTypes()[0]['type']);
        self::assertSame('dbo', JoomlaMapping::ident('--source-schema', 'dbo'));
        self::assertNull(JoomlaMapping::ident('--source-schema', null));
        self::assertNull(JoomlaMapping::ident('--source-schema', ''));
    }

    public function testSeblodFaqMarkersBothTemplates(): void
    {
        $old = '::template_faq_title|0|f::Q1::/template_faq_title|0|f::<br />::template_faq_desc|0|f::A1::/template_faq_desc|0|f::';
        $new = '::tmpl_faq_title|0|g::Q2::/tmpl_faq_title|0|g::<br />::tmpl_faq_desc|0|g::<p>A2</p>::/tmpl_faq_desc|0|g::';
        self::assertSame([['q' => 'Q1', 'a' => 'A1']], SeblodMarkers::faq($old, 'f'));
        self::assertSame([['q' => 'Q2', 'a' => 'A2']], SeblodMarkers::faq($new, 'g'));
    }
}
