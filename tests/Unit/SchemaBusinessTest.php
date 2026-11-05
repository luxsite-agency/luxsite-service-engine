<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Twig\SchemaExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class SchemaBusinessTest extends TestCase
{
    private function business(array $config = [], string $type = 'LocalBusiness'): array
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['strict_variables' => true]);
        $twig->addExtension(new SchemaExtension($config));
        $twig->addGlobal('schema_country', 'UA');
        $twig->addGlobal('theme', new class {
            public function url(string $key): ?string { return null; }
        });
        $html = $twig->createTemplate("{% import 'services/partials/_schema.html.twig' as schema %}{{ schema.business(site, city, 'uk', 'https://example.com/', 'https://example.com', {}) }}")->render([
            'site' => (new ServiceSite())->setName('Movers')->setSchemaType($type),
            'city' => (new ServiceCity())->setNameNomUk('Kyiv')->setAddressUk('Old Street, 4'),
        ]);
        self::assertSame(1, preg_match('~<script[^>]*>(.*?)</script>~s', $html, $match));

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testDefaultsPreserveCityBusiness(): void
    {
        $data = $this->business(['legal_name' => 'Legal name']);
        self::assertSame('Movers - Kyiv', $data['name']);
        self::assertSame('$$', $data['priceRange']);
        self::assertSame(['@type' => 'City', 'name' => 'Kyiv'], $data['areaServed']);
        self::assertSame(['@type' => 'PostalAddress', 'addressLocality' => 'Kyiv', 'addressCountry' => 'UA', 'streetAddress' => 'Old Street, 4'], $data['address']);
        self::assertSame('Legal name', $data['legalName']);
    }

    public function testOverridesAndOrganization(): void
    {
        $config = ['name_with_city' => false, 'price_range' => '$$$', 'address' => ['street' => '123 Main St', 'locality' => 'Southampton', 'region' => 'NY', 'postal_code' => '11968', 'country' => 'US'], 'area_served' => ['Hamptons', 'Long Island']];
        $data = $this->business($config);
        self::assertSame('Movers', $data['name']);
        self::assertSame('$$$', $data['priceRange']);
        self::assertSame(['@type' => 'PostalAddress', 'streetAddress' => '123 Main St', 'addressLocality' => 'Southampton', 'addressRegion' => 'NY', 'postalCode' => '11968', 'addressCountry' => 'US'], $data['address']);
        self::assertSame([['@type' => 'Place', 'name' => 'Hamptons'], ['@type' => 'Place', 'name' => 'Long Island']], $data['areaServed']);
        self::assertSame(['@type' => 'Place', 'name' => 'Hamptons'], $this->business(['area_served' => 'Hamptons'])['areaServed']);
        self::assertArrayNotHasKey('priceRange', $this->business(['price_range' => '']));
        self::assertArrayNotHasKey('priceRange', $this->business([], 'Organization'));
    }

    public static function invalidConfigs(): iterable
    {
        yield [['price_range' => 100]];
        yield [['name_with_city' => 'false']];
        yield [['address' => 'Main St']];
        yield [['address' => ['street' => false]]];
        yield [['area_served' => ['NY' => 'New York']]];
        yield [['area_served' => [123]]];
    }

    #[DataProvider('invalidConfigs')]
    public function testInvalidTypesAreRejected(array $config): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SchemaExtension($config);
    }
}
