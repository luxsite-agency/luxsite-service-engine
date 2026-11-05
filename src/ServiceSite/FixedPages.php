<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\ServiceSite\Redirect\RedirectPath;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FixedPages extends AbstractExtension
{
    public const KEYS = ['faq', 'contacts', 'reviews', 'privacy', 'terms'];

    public function __construct(
        private readonly SiteLocales $locales,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%app.fixed_pages%')] private readonly array $pages = [],
    ) {
        foreach ($pages as $key => $value) {
            if (!in_array($key, self::KEYS, true) || (!is_bool($value) && (!is_string($value) || !str_starts_with($value, '/') || str_contains($value, '\\') || RedirectPath::normalizeTarget($value) === null))) {
                throw new \InvalidArgumentException('Invalid app.fixed_pages entry: ' . $key);
            }
        }
    }

    public function enabled(string $key): bool
    {
        return ($this->pages[$key] ?? true) === true;
    }

    public function path(string $key, ?string $locale = null): ?string
    {
        $value = $this->pages[$key] ?? true;
        $locale ??= $this->locales->current();
        if ($value === false) {
            return null;
        }
        if ($value === true) {
            return $this->urls->generate('sv_' . $key, ['_locale' => $locale]);
        }
        $target = RedirectPath::normalizeTarget($value);
        foreach (array_slice($this->locales->deployment(), 1) as $prefix) {
            if (str_starts_with($target, '/' . $prefix . '/') || $target === '/' . $prefix) {
                return $target;
            }
        }

        return ($locale === $this->locales->default() ? '' : '/' . $locale) . $target;
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('fixed_page_path', $this->path(...))];
    }
}
