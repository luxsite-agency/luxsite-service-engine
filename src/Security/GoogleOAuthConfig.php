<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Security;

use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Чи працює вхід через Google для відгуків на сайті. Без нього сайт не показує відвідувачу ні кнопку «Увійти через
 * Google», ні технічне повідомлення: попередження лише в адмінці (дашборд, «Відгуки», «Налаштування») і в лозі.
 * У шаблонах — глобал {{ google_oauth.configured }} (сайт поточного хоста). Ключі: адмінка → Налаштування сайту, запасне —
 * .env.local (GoogleOAuthCredentials). Тут лише стан — ні id, ні секрету: об'єкт бачить будь-який шаблон.
 */
final class GoogleOAuthConfig
{
    public function __construct(
        private readonly GoogleOAuthCredentials $credentials,
        private readonly RequestStack $requests,
    ) {
    }

    /** Сайт — явний або тенант поточного запиту (на хості адмінки його нема → лише .env.local). */
    public function isConfigured(?ServiceSite $site = null): bool
    {
        return GoogleKeySource::None !== $this->source($site);
    }

    public function source(?ServiceSite $site = null): GoogleKeySource
    {
        return $this->credentials->resolve($site ?? $this->requestSite())['source'];
    }

    /** Код причини, чому вимкнено (GoogleOAuthCredentials::resolve), або null. */
    public function problem(?ServiceSite $site = null): ?string
    {
        return $this->credentials->resolve($site ?? $this->requestSite())['problem'];
    }

    /** Текст попередження для логу (адмінка показує свій, з посиланням на налаштування). */
    public function warning(): string
    {
        return 'Вхід через Google не налаштовано (адмінка → Налаштування сайту → «Вхід через Google»; запасне — '
            . 'GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET у .env.local): відвідувачі не бачать форму «Залишити відгук», '
            . 'нові відгуки — лише через адмінку.';
    }

    private function requestSite(): ?ServiceSite
    {
        $site = $this->requests->getCurrentRequest()?->attributes->get(ServiceHostSubscriber::ATTR_SITE);

        return $site instanceof ServiceSite ? $site : null;
    }
}
