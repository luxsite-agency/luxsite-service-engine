<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use App\AdminApi\Auth\ApiTokenContext;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Що поточний токен реально може (дозвіл у токені ∩ роль власника, ScopeVoter) і чого API не вміє зовсім — для /me, MCP `me`
 * і сторінки API-токенів. Без цього агент клієнта шукав «адресу для фото» на десятках шляхів і вирішив, що API не працює
 * (23.10.2026). Поля лише додаються: клієнти, що читають старі ключі /me, не ламаються.
 */
final class ApiCapabilities
{
    /** Шляхи живого дизайну (ThemeService::TEMPLATE_RE і CSS). */
    public const array THEME_PATHS = ['services/**/*.twig', 'partials/**/*.twig', ThemeService::CSS];

    /** Чого API не змінює ніколи — лише адмінка (https://<ADMIN_HOST>/admin) або розробник. */
    public const array ADMIN_ONLY = [
        'images' => 'Картинки й фото (завантаження, заміна, обкладинки послуг, категорій, постів, міст) — лише форма запису в адмінці; через API — тільки підпис image_alt',
        'order' => 'Порядок послуг і категорій (у прайсі порядок — поле position)',
        'structure' => 'Створення й видалення послуг, категорій, районів, міст; slug (адреси сторінок), категорія послуги',
        'cities' => 'Міста й домени, де послуга надається й де показуємо ціну',
        'other' => 'Відгуки, команда, «Наші роботи», калькулятор',
        'users' => 'Користувачі, ролі, чужі токени',
        'settings' => 'Налаштування сайту: GTM, GA4, Ads, вхід через Google, коди в head/body',
        'leads' => 'Заявки',
    ];

    /** Типові задачі → як їх зробити (порада агенту, а не правило рушія). */
    private const array RECIPES = [
        ['task' => 'Назви, описи, SEO розділів (категорій) — напр. «центри», «напрями»', 'how' => 'PATCH {site_api_url}/categories/{id} або MCP update kind=categories, поля title_uk, title_ru, desc_uk, meta_title_uk…', 'scope' => 'content:write'],
        ['task' => 'Тексти, FAQ, SEO послуги', 'how' => 'PATCH {site_api_url}/items/{id} або MCP update kind=items', 'scope' => 'content:write'],
        ['task' => 'Текст послуги чи категорії для одного міста', 'how' => 'POST {site_api_url}/item-texts або category-texts, MCP upsert_city_texts', 'scope' => 'content:write'],
        ['task' => 'Ціни', 'how' => 'PATCH {site_api_url}/prices/{id} або MCP update kind=prices', 'scope' => 'prices:write'],
        ['task' => 'Кольори, шрифти, відступи (напр. колір панелі, кнопок)', 'how' => 'theme.css: PUT {site_api_url}/theme/theme.css або MCP theme_put path=theme.css', 'scope' => 'theme:write'],
        ['task' => 'Розмітка блоків сторінок', 'how' => 'шаблони services/**, partials/**: MCP theme_list → theme_get → theme_put (extends @engine/…)', 'scope' => 'theme:write'],
        ['task' => 'Статті в блог з content-studio', 'how' => 'PUT https://<домен міста>/api/studio/articles/{id} (адреси — endpoints.sites[].studio)', 'scope' => 'studio:write'],
        ['task' => 'Фото й картинки', 'how' => 'лише адмінка: відкрити запис → поле картинки → Зберегти', 'scope' => null],
    ];

    public function __construct(
        private readonly AuthorizationCheckerInterface $auth,
        private readonly ApiTokenContext $context,
    ) {
    }

    /**
     * Блок `capabilities` для /me: діючі дозволи, що читати й які поля писати, дизайн, студія, «лише в адмінці», рецепти.
     *
     * @return array<string, mixed>
     */
    public function forCurrentToken(): array
    {
        $effective = [];
        foreach (ApiScope::cases() as $scope) {
            $effective[$scope->value] = $this->auth->isGranted('scope:' . $scope->value);
        }
        $token = $this->context->current();
        $inToken = $token?->getScopeValues() ?? [];
        $inactive = [];
        foreach ($inToken as $value) {
            if (\is_string($value) && empty($effective[$value])) {
                $scope = ApiScope::tryFrom($value);
                $inactive[$value] = sprintf('є в токені, але власнику токена бракує ролі %s — дозвіл не діє', $scope?->requiredRole() ?? 'ROLE_MODERATOR');
            }
        }

        $write = [];
        if ($effective[ApiScope::ContentWrite->value]) {
            foreach (ContentService::FIELDS as $kind => $fields) {
                if ($kind === 'prices' && !$effective[ApiScope::PricesWrite->value]) {
                    continue;
                }
                $write[$kind] = array_keys($fields);
            }
        }

        return [
            'scopes' => $effective,
            'inactive_scopes' => $inactive === [] ? new \stdClass() : $inactive,
            'read' => $effective[ApiScope::ContentRead->value] ? explode('|', ContentService::KINDS) : [],
            'write' => $write === [] ? new \stdClass() : $write,
            'create' => $effective[ApiScope::ContentWrite->value] ? ['item-texts', 'category-texts'] : [],
            'theme' => [
                'allowed' => $effective[ApiScope::ThemeWrite->value],
                'paths' => self::THEME_PATHS,
                'how' => 'REST {site_api_url}/theme (GET перелік, GET/PUT/DELETE /theme/{path}, POST /theme/{path}/revert) або MCP theme_list, theme_get, theme_put, theme_delete, theme_revert',
            ],
            'studio' => [
                'allowed' => $effective[ApiScope::StudioWrite->value],
                'how' => 'Лише на доменах міст сайту: GET /api/studio/ping, PUT /api/studio/articles/{id} (адреси — endpoints.sites[].studio)',
            ],
            'admin_only' => array_values(self::ADMIN_ONLY),
            'recipes' => self::recipes($effective),
            'note' => '{site_api_url} — endpoints.sites[].site_api_url; поля для PATCH — лише з write.<kind>, інше — 422 з переліком дозволених',
        ];
    }

    /**
     * Рецепти з позначкою, чи діє потрібний дозвіл (для сторінки токенів — без токена: allowed = null).
     *
     * @param array<string, bool>|null $effective
     *
     * @return list<array{task: string, how: string, scope: string|null, allowed: bool|null}>
     */
    public static function recipes(?array $effective = null): array
    {
        $out = [];
        foreach (self::RECIPES as $r) {
            $allowed = match (true) {
                $r['scope'] === null => false, // не через API взагалі
                $effective === null => null,
                // прайс пишеться PATCH-ем контенту: потрібні обидва дозволи (ContentService::patch)
                $r['scope'] === ApiScope::PricesWrite->value => ($effective[ApiScope::PricesWrite->value] ?? false) && ($effective[ApiScope::ContentWrite->value] ?? false),
                default => $effective[$r['scope']] ?? false,
            };
            $out[] = $r + ['allowed' => $allowed];
        }

        return $out;
    }
}
