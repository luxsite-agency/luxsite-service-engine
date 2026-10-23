<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

/** Відповідь на адресу з менеджера редиректів: 301/302 — переадресація на target, 404/410 — сторінка помилки сайту. */
enum RedirectCode: int
{
    case Permanent = 301;
    case Temporary = 302;
    case NotFound = 404;
    case Gone = 410;

    public function isRedirect(): bool
    {
        return self::Permanent === $this || self::Temporary === $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Permanent => '301 — назавжди',
            self::Temporary => '302 — тимчасово',
            self::NotFound => '404 — не знайдено',
            self::Gone => '410 — видалено',
        };
    }
}
