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
 *    /api/studio/<slug>/… (ендпойнт не світиться на кожному домені мережі). Типово true — як було.
 */
final class BrandSettings
{
    public const KEYS = ['theme', 'outbound', 'studio_on_public_host'];

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

    public function studioOnPublicHost(ServiceSite $site): bool
    {
        return (bool) ($this->brands[$site->getSlug()]['studio_on_public_host'] ?? true);
    }
}
