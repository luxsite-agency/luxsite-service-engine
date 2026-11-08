<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use App\Admin\CopyUrlField;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Хелпери шаблонів адмінки (не для живих тем сайту: у LiveThemePolicy їх нема). */
final class AdminExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // адреса сайту з домену чи хоста для кнопки «скопіювати адресу» (CopyUrlField)
            new TwigFunction('sv_copy_url', static fn (mixed $host): ?string => \is_scalar($host) ? CopyUrlField::url((string) $host) : null),
        ];
    }
}
