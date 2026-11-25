<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class SchemaExtension extends AbstractExtension implements GlobalsInterface
{
    private array $schema;

    public function __construct(#[Autowire('%app.schema%')] array $schema = [])
    {
        $this->schema = array_replace([
            'legal_name' => '', 'founder' => '', 'opening_hours' => '',
            'price_range' => '$$', 'address' => [], 'area_served' => '', 'name_with_city' => true,
        ], $schema);
        foreach (['legal_name', 'founder', 'opening_hours', 'price_range'] as $key) {
            if (!is_string($this->schema[$key])) {
                throw new \InvalidArgumentException('app.schema.' . $key . ' must be a string.');
            }
        }
        if (!is_bool($this->schema['name_with_city']) || !is_array($this->schema['address'])) {
            throw new \InvalidArgumentException('Invalid app.schema name_with_city or address.');
        }
        $this->schema['address'] = array_replace(array_fill_keys(['street', 'locality', 'region', 'postal_code', 'country'], ''), $this->schema['address']);
        foreach ($this->schema['address'] as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('app.schema.address values must be strings.');
            }
        }
        $area = $this->schema['area_served'];
        if (!is_string($area) && (!is_array($area) || !array_is_list($area))) {
            throw new \InvalidArgumentException('app.schema.area_served must be a string or list of strings.');
        }
        foreach ((array) $area as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('app.schema.area_served values must be strings.');
            }
        }
    }

    public function getGlobals(): array
    {
        return ['schema_business' => $this->schema];
    }
}
