<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use App\ServiceSite\LeadSpamGuard;
use App\ServiceSite\Turnstile;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Захист форм заявок у шаблонах (LeadSpamGuard):
 *  - `lead_form_ts()` - підписана мітка часу показу форми для прихованого поля `_ft`:
 *    `<input type="hidden" name="_ft" value="{{ lead_form_ts() }}">`;
 *  - `lead_turnstile()` - `{enabled, site_key}` Cloudflare Turnstile (app.lead_spam.turnstile): віджет —
 *    `{{ include('services/partials/_turnstile.html.twig') }}` перед кнопкою форми; скрипт підключає base.
 */
final class LeadFormExtension extends AbstractExtension
{
    public function __construct(
        private readonly LeadSpamGuard $guard,
        private readonly Turnstile $turnstile,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('lead_form_ts', fn (): string => $this->guard->formToken()),
            new TwigFunction('lead_turnstile', fn (): array => ['enabled' => $this->turnstile->enabled(), 'site_key' => $this->turnstile->siteKey()]),
        ];
    }
}
