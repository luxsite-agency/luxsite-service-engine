<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Security;

use App\Entity\ServiceSite;
use App\Settings\SiteSettingKeys;
use App\Settings\SiteSettings;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ключі OAuth-клієнта Google для сайту (рішення shop-engine 13.10.2026: ключі вводить клієнт в адмінці). Пріоритет —
 * адмінка → Налаштування сайту: перемикач «Увімкнено» + Client ID правильного формату + Client Secret, що розшифровується
 * (SecretBox, SETTINGS_SECRET_KEY). Поки перемикач вимкнений, працює запасне джерело — env GOOGLE_CLIENT_ID /
 * GOOGLE_CLIENT_SECRET з .env.local (так працювали сайти до 21.10.2026). Перемикач увімкнено, а ключі неповні — вхід
 * вимкнений (problem() каже чому), а не тихий відкат на env.
 *
 * ⛔ Не Twig-глобал і не віддавати в шаблони: resolve() містить секрет. Для шаблонів — GoogleOAuthConfig (лише стан).
 */
final class GoogleOAuthCredentials
{
    public function __construct(
        private readonly SiteSettings $settings,
        #[Autowire(env: 'GOOGLE_CLIENT_ID')] private readonly string $envClientId = '',
        #[Autowire(env: 'GOOGLE_CLIENT_SECRET')] #[SensitiveParameter] private readonly string $envClientSecret = '',
    ) {
    }

    /**
     * problem: disabled (перемикач вимкнено, env нема), client_id_missing, client_id_invalid, secret_missing,
     * secret_no_key, secret_unreadable. null — вхід увімкнено.
     *
     * @return array{source: GoogleKeySource, id: string, secret: string, problem: string|null}
     */
    public function resolve(?ServiceSite $site): array
    {
        if (null !== $site && $this->settings->getBool($site, SiteSettingKeys::GOOGLE_SIGN_IN_ENABLED)) {
            $raw = trim((string) $this->settings->get($site, SiteSettingKeys::GOOGLE_CLIENT_ID));
            $id = SiteSettingKeys::validValue(SiteSettingKeys::GOOGLE_CLIENT_ID, $raw);
            if (null === $id) {
                return self::off('' === $raw ? 'client_id_missing' : 'client_id_invalid');
            }
            $state = $this->settings->secretState($site, SiteSettingKeys::GOOGLE_CLIENT_SECRET);
            $secret = $state->isReady() ? $this->settings->secret($site, SiteSettingKeys::GOOGLE_CLIENT_SECRET) : null;
            if (null === $secret) {
                return self::off('secret_' . $state->value);
            }

            return ['source' => GoogleKeySource::Settings, 'id' => $id, 'secret' => $secret, 'problem' => null];
        }
        $id = trim($this->envClientId);
        $secret = trim($this->envClientSecret);
        if ('' !== $id && '' !== $secret) {
            return ['source' => GoogleKeySource::Env, 'id' => $id, 'secret' => $secret, 'problem' => null];
        }

        return self::off('disabled');
    }

    /** @return array{source: GoogleKeySource, id: string, secret: string, problem: string|null} */
    private static function off(string $problem): array
    {
        return ['source' => GoogleKeySource::None, 'id' => '', 'secret' => '', 'problem' => $problem];
    }
}
