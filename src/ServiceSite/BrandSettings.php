<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceSite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Налаштування бренду з паку, крім теми (app.brands.<slug>, 22.10.2026; тему читає SiteTheme):
 *  - outbound.follow: [домени] — зовнішні посилання в статтях студії на ці домени (і їхні піддомени) без nofollow;
 *    решта зовнішніх — nofollow, як і раніше (рішення координатора 22.10.2026: allowlist у паку);
 *  - studio_on_public_host: false — приймач студії /api/studio на доменах бренду вимкнено (404), лише через адмін-хост
 *    /api/studio/<slug>/… (ендпойнт не світиться на кожному домені мережі). Типово true — як було;
 *  - templates: <тека> — шаблони бренду з templates/site/brands/<тека>/ замість теки зі slug бренду: кілька брендів з
 *    одним набором (24.10.2026: сім новинних брендів поруч з англійськими). Читає App\Twig\BrandTemplateLoader;
 *  - blog_per_page: N — статей на сторінку списку й головної бренду замість app.blog_per_page (новини — 20, решта — 12);
 *  - category_url: root — рубрики блогу від кореня (/<рубрика>/ замість /<блог>/category/<рубрика>/; 24.10.2026, новинні
 *    бренди з WP-адресами). Типово — як було. Маршрути — BlogController, генерація — App\Routing\BrandRouter.
 */
final class BrandSettings
{
    public const KEYS = ['theme', 'outbound', 'studio_on_public_host', 'templates', 'blog_per_page', 'category_url'];

    /** @param array<string, array<string, mixed>> $brands app.brands */
    public function __construct(
        #[Autowire('%app.brands%')]
        private readonly array $brands = [],
    ) {
    }

    /** @return list<string> домени без nofollow (нижній регістр, без www.) */
    public function followDomains(ServiceSite $site): array
    {
        $list = (array) ($this->brands[$site->getSlug()]['outbound']['follow'] ?? []);

        return array_values(array_unique(array_map(static fn ($d): string => (string) preg_replace('/^www\./', '', strtolower(trim((string) $d))), $list)));
    }

    /** Тека шаблонів бренду в templates/site/brands/: app.brands.<slug>.templates або сам slug */
    public function templatesDir(ServiceSite $site): string
    {
        $dir = $this->brands[$site->getSlug()]['templates'] ?? $site->getSlug();
        if (!\is_string($dir) || !preg_match('~^[a-z0-9-]+$~', $dir)) {
            throw new \InvalidArgumentException(sprintf('app.brands.%s.templates: очікується назва теки [a-z0-9-].', $site->getSlug()));
        }

        return $dir;
    }

    /** Статей на сторінку бренду: app.brands.<slug>.blog_per_page або $default (app.blog_per_page) */
    public function blogPerPage(ServiceSite $site, int $default): int
    {
        $n = $this->brands[$site->getSlug()]['blog_per_page'] ?? null;

        return \is_int($n) && $n >= 0 ? $n : $default;
    }

    /** Рубрики блогу бренду від кореня сайту (app.brands.<slug>.category_url: root) */
    public function categoryRoot(ServiceSite $site): bool
    {
        return ($this->brands[$site->getSlug()]['category_url'] ?? null) === 'root';
    }

    public function studioOnPublicHost(ServiceSite $site): bool
    {
        return (bool) ($this->brands[$site->getSlug()]['studio_on_public_host'] ?? true);
    }
}
