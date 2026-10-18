<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace Luxsite\GmbReviewsBundle;

use Luxsite\GmbReviews\Client;
use Luxsite\GmbReviewsBundle\Twig\GmbReviewsExtension;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * config/packages/gmb_reviews.yaml:
 *   gmb_reviews:
 *     token: '%env(GMB_REVIEWS_TOKEN)%'   # типовий токен (одна картка на сайт)
 *     base_url: 'https://gmb.luxsite.agency'
 *     ttl: 600
 *     cache_pool: 'cache.app'
 *
 * У шаблоні: {{ gmb_reviews() }} або {{ gmb_reviews('<token>', {layout: 'carousel'}) }};
 * розмітка окремо в <head>: {{ gmb_reviews_jsonld('<token>', app.request.uri) }}.
 */
final class GmbReviewsBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('token')->defaultNull()->end()
                ->scalarNode('base_url')->defaultValue(Client::DEFAULT_BASE)->end()
                ->integerNode('ttl')->defaultValue(Client::TTL)->end()
                ->scalarNode('cache_pool')->defaultValue('cache.app')->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('gmb_reviews.cache', Psr16Cache::class)
            ->args([new Reference($config['cache_pool'])]);

        $services->set(Client::class)
            ->args([$config['base_url'], new Reference('gmb_reviews.cache'), $config['ttl']])
            ->public();

        $services->set(GmbReviewsExtension::class)
            ->args([new Reference(Client::class), $config['token'], new Reference('request_stack', \Symfony\Component\DependencyInjection\ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->tag('twig.extension');
    }
}
