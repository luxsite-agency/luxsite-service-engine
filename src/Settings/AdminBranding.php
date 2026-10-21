<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

use App\Entity\ServiceSite;
use App\Repository\ServiceSiteRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Назва адмінки (власник 21.10.2026: «замість "Сервісні сайти" — назва сайту»): бічне меню, заголовок дашборду, <title>
 * і h1 сторінки входу. Сайт адмінки — той, чий апекс-домен є суфіксом ADMIN_HOST (admin.example.com → example.com);
 * не знайшовся, а сайт у БД один — він. Назва — налаштування «Назва адмінки» цього сайту, інакше назва сайту. Сайтів
 * кілька й жоден не збігся з хостом (локальний стек на кілька брендів) — «Сервісні сайти», як було.
 */
final class AdminBranding implements ResetInterface
{
    public const string DEFAULT_TITLE = 'Сервісні сайти';

    private bool $resolved = false;
    private ?ServiceSite $site = null;
    private int $count = 0;

    public function __construct(
        private readonly ServiceSiteRepository $sites,
        private readonly SiteSettings $settings,
        #[Autowire(env: 'ADMIN_HOST')] private readonly string $adminHost = '',
    ) {
    }

    /** Сайт, якому належить адмінка, або null (кілька сайтів, хост адмінки не збігся з жодним апексом). */
    public function site(): ?ServiceSite
    {
        $this->resolve();

        return $this->site;
    }

    /** У БД рівно один сайт — меню й підписи кажуть «Сайт», а не «Сайти-бренди». */
    public function singleSite(): bool
    {
        $this->resolve();

        return 1 === $this->count;
    }

    public function title(): string
    {
        $site = $this->site();
        if (null === $site) {
            return self::DEFAULT_TITLE;
        }
        $custom = $this->settings->valid($site, SiteSettingKeys::ADMIN_TITLE);

        return $custom ?? (trim($site->getName()) !== '' ? trim($site->getName()) : self::DEFAULT_TITLE);
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->site = null;
        $this->count = 0;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;
        $all = $this->sites->findBy([], ['id' => 'ASC']);
        $this->count = \count($all);
        $host = strtolower(trim($this->adminHost));
        $matches = array_values(array_filter($all, static function (ServiceSite $s) use ($host): bool {
            $apex = strtolower(trim($s->getApexDomain()));

            return '' !== $apex && '' !== $host && ($host === $apex || str_ends_with($host, '.' . $apex));
        }));
        if (1 === \count($matches)) {
            $this->site = $matches[0];
        } elseif (1 === $this->count) {
            $this->site = $all[0];
        }
    }
}
