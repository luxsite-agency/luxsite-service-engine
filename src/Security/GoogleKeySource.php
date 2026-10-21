<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Security;

/**
 * Звідки ключі OAuth Google для входу відвідувачів (відгуки): Settings — адмінка → Налаштування сайту (перемикач
 * «Увімкнено» + Client ID + Client Secret), Env — GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET з .env.local (запасне, поки
 * перемикач вимкнено), None — вхід вимкнено.
 */
enum GoogleKeySource: string
{
    case Settings = 'settings';
    case Env = 'env';
    case None = 'none';
}
