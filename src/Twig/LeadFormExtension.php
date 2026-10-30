<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use App\ServiceSite\LeadSpamGuard;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `lead_form_ts()` - підписана мітка часу показу форми для прихованого поля `_ft`
 * (перевіряє LeadSpamGuard). У шаблоні паку:
 * `<input type="hidden" name="_ft" value="{{ lead_form_ts() }}">`.
 */
final class LeadFormExtension extends AbstractExtension
{
    public function __construct(private readonly LeadSpamGuard $guard)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('lead_form_ts', fn (): string => $this->guard->formToken()),
        ];
    }
}
