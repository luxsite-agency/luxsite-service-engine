<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Symfony\Component\Routing\RouteCollection;

/**
 * Мови розгортання в маршрутах (app.locales, 22.10.2026). Публічні маршрути рушія оголошені парою
 * `['uk' => '/x/', 'ru' => '/ru/x/']`; розгортання з іншим набором мов (сателіти: [en]) переписує їх один раз при
 * компіляції роутера (Kernel::loadRoutes): варіант без префікса (`<name>.uk`) стає першою мовою розгортання, решта мов
 * отримують префікс `/<мова>`, мови поза набором прибираються.
 *
 * При типовому [uk, ru] колекція повертається та сама (ранній вихід) — маршрути живих сайтів не змінюються.
 * Інакше будується нова колекція в тому самому порядку і з тими самими пріоритетами: RouteCollection::add() переносить
 * маршрут у кінець і скидає пріоритет, тож перебудова без getPriority() змінила б порядок зіставлення.
 */
final class LocalizedRoutes
{
    /** Мови, якими оголошені маршрути в атрибутах і config/routes/catalog/*.yaml */
    public const DECLARED = ['uk', 'ru'];
    /** Мова варіанта без префікса в оголошеннях */
    private const UNPREFIXED = 'uk';

    /** @param list<string> $locales app.locales, перша — без префікса */
    public static function apply(RouteCollection $routes, array $locales): RouteCollection
    {
        if ($locales === self::DECLARED) {
            return $routes;
        }
        self::assertLocales($locales);

        $out = new RouteCollection();
        foreach ($routes->all() as $name => $route) {
            $canonical = $route->getDefault('_canonical_route');
            $locale = $route->getDefault('_locale');
            if (!\is_string($canonical) || !\in_array($locale, self::DECLARED, true) || $name !== $canonical . '.' . $locale) {
                $out->add($name, $route, $routes->getPriority($name) ?? 0);
                continue;
            }
            // варіанти інших оголошених мов не переносимо: усі мови розгортання будуються з варіанта без префікса
            if ($locale !== self::UNPREFIXED) {
                continue;
            }
            foreach ($locales as $i => $target) {
                $variant = clone $route;
                if ($i > 0) {
                    $variant->setPath('/' . $target . $route->getPath());
                }
                // setDefault/setRequirement('_locale') локалізованого маршруту Symfony ігнорує (Route::isLocalized), тож
                // defaults замінюються цілком (тоді маршрут ще не «локалізований»), а потім — requirement
                $variant->setDefaults(['_locale' => $target] + $route->getDefaults());
                $variant->setRequirement('_locale', preg_quote($target));
                $out->add($canonical . '.' . $target, $variant, $routes->getPriority($name) ?? 0);
            }
        }
        foreach ($routes->getAliases() as $alias => $target) {
            $copy = $out->addAlias($alias, $target->getId());
            if ($target->isDeprecated()) {
                $d = $target->getDeprecation('%alias_id%');
                $copy->setDeprecated($d['package'], $d['version'], $d['message']);
            }
        }
        foreach ($routes->getResources() as $resource) {
            $out->addResource($resource);
        }

        return $out;
    }

    /** @param list<string> $locales */
    public static function assertLocales(array $locales): void
    {
        if ($locales === [] || !array_is_list($locales)) {
            throw new \InvalidArgumentException('app.locales: очікується непорожній список мов, перша — без префікса в адресі.');
        }
        foreach ($locales as $l) {
            if (!\is_string($l) || !preg_match('/^[a-z]{2}$/', $l)) {
                throw new \InvalidArgumentException(sprintf('app.locales: «%s» — очікується код мови з двох латинських літер (uk, ru, en).', (string) json_encode($l)));
            }
        }
        if (\count(array_unique($locales)) !== \count($locales)) {
            throw new \InvalidArgumentException('app.locales: мова повторюється.');
        }
    }
}
