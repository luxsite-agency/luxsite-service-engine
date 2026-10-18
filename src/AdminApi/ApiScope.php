<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

/**
 * Скоупи персональних API-токенів (власник 15.10.2026: «менеджери самі підключають проєкт до контент-студії, правлять
 * контент»; модель — shop-engine docs/admin-api.md). Скоуп діє лише разом з роллю власника токена (ScopeVoter):
 * токен не може більше за людину. Заявки, користувачі, сайти/міста, налаштування — не через API.
 */
enum ApiScope: string
{
    case ContentRead = 'content:read';
    case ContentWrite = 'content:write';
    case PricesWrite = 'prices:write';
    case StudioWrite = 'studio:write';
    case ThemeWrite = 'theme:write';

    public function label(): string
    {
        return match ($this) {
            self::ContentRead => 'Читання контенту: послуги, категорії, міста, райони, блог, прайс',
            self::ContentWrite => 'Правка контенту: тексти, SEO, FAQ, активність, тексти по містах',
            self::PricesWrite => 'Правка прайсу (ціни послуг по містах)',
            self::StudioWrite => 'Приймач content-studio: статті в блог (lingualcloud.com)',
            self::ThemeWrite => 'Правка дизайну: живі шаблони (services/**, partials/**) і theme.css з перевіркою рендера й історією версій',
        };
    }

    /** Роль, яка дає те саме в адмінці; без неї скоуп у токені нічого не дає. */
    public function requiredRole(): string
    {
        return 'ROLE_MODERATOR';
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $scope = \is_string($value) ? self::tryFrom($value) : null;
            if ($scope === null) {
                throw new \InvalidArgumentException(sprintf('Невідомий скоуп «%s»', \is_scalar($value) ? (string) $value : get_debug_type($value)));
            }
            if (!\in_array($scope, $out, true)) {
                $out[] = $scope;
            }
        }

        return $out;
    }

    /** @return array<string, string> назва → значення (для форм і фільтрів) */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value . ' — ' . $case->label()] = $case->value;
        }

        return $choices;
    }
}
