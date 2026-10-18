<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Чи вписані креди Google OAuth у .env.local. Без них вхід для відгуків не працює, тож сайт не показує відвідувачу
 * ні кнопку «Увійти через Google», ні технічне повідомлення: попередження лише в адмінці (дашборд, «Відгуки») і в лозі.
 * У шаблонах — глобал {{ google_oauth.configured }}.
 */
final class GoogleOAuthConfig
{
    public function __construct(
        #[Autowire(env: 'GOOGLE_CLIENT_ID')]
        private readonly string $clientId,
        #[Autowire(env: 'GOOGLE_CLIENT_SECRET')]
        private readonly string $clientSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->clientId) !== '' && trim($this->clientSecret) !== '';
    }

    /** Текст попередження для адмінки й логу */
    public function warning(): string
    {
        return 'Вхід через Google не налаштовано (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET у .env.local): '
            .'відвідувачі не бачать форму «Залишити відгук», нові відгуки — лише через адмінку.';
    }
}
