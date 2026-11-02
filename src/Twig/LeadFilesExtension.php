<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use App\ServiceSite\LeadFiles;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Файли в заявці для шаблонів форм: lead_files() → {enabled, accept, max_files, max_file_mb, max_total_mb}. enabled: false —
 * форма без поля файлів і без multipart (як до app.lead_files). Окреме розширення — щоб не перетинатися з гілками форм.
 */
final class LeadFilesExtension extends AbstractExtension
{
    public function __construct(private readonly LeadFiles $files)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('lead_files', $this->config(...))];
    }

    /** @return array{enabled: bool, accept: string, max_files: int, max_file_mb: int|float, max_total_mb: int|float} */
    public function config(): array
    {
        $c = $this->files->config();

        return [
            'enabled' => $this->files->enabled(),
            'accept' => $this->files->accept(),
            'max_files' => (int) $c['max_files'],
            'max_file_mb' => $c['max_file_mb'],
            'max_total_mb' => $c['max_total_mb'],
        ];
    }
}
