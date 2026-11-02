<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use App\ServiceSite\Applications;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Форми анкет (app.applications) у шаблонах: application_form(ключ, locale) → {key, title, consent, files, required, fields:
 * [{name, label, type, required, options: [{value, label}]}]} мовою сторінки; null — такої форми в паку нема.
 */
final class ApplicationsExtension extends AbstractExtension
{
    public function __construct(private readonly Applications $applications)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('application_form', $this->form(...))];
    }

    /** @return array<string, mixed>|null */
    public function form(string $key, string $locale = 'uk'): ?array
    {
        return $this->applications->has($key) ? $this->applications->view($key, $locale) : null;
    }
}
