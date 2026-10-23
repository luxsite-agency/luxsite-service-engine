<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use App\Entity\ServiceSite;
use App\Repository\ServiceCityRepository;
use App\Settings\AdminBranding;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Справжні адреси API одного встановлення (23.10.2026: агент клієнта стукав на хост сайту, отримував 404 і вирішував, що
 * «API нічого не дає»). REST /api/admin/v1 і MCP /mcp живуть ЛИШЕ на ADMIN_HOST, приймач content-studio /api/studio — ЛИШЕ
 * на хостах міст сайту. Один збирач для /me, MCP, підказок wrong_host (AdminHostSubscriber) і сторінки API-токенів.
 * Хост адмінки публічний; міста — лише того сайту, про який питають (чужих сайтів мультисайту не перелічуємо).
 */
final class ApiEndpoints
{
    public const string DOCS_URL = 'https://github.com/luxsite-agency/luxsite-service-engine/blob/main/docs/admin-api.md';
    public const string ADMIN_API_PATH = '/api/admin/v1';
    public const string MCP_PATH = '/mcp';
    public const string STUDIO_PATH = '/api/studio';

    public function __construct(
        private readonly ServiceCityRepository $cities,
        private readonly AdminBranding $branding,
        #[Autowire(env: 'ADMIN_HOST')] private readonly string $adminHost,
    ) {
    }

    public function adminHost(): string
    {
        return strtolower(trim($this->adminHost));
    }

    public function adminUrl(): string
    {
        return 'https://' . $this->adminHost() . '/admin';
    }

    public function adminApiUrl(): string
    {
        return 'https://' . $this->adminHost() . self::ADMIN_API_PATH;
    }

    public function meUrl(): string
    {
        return $this->adminApiUrl() . '/me';
    }

    public function mcpUrl(): string
    {
        return 'https://' . $this->adminHost() . self::MCP_PATH;
    }

    /** Сайт, якому належить хост адмінки (AdminBranding: апекс — суфікс ADMIN_HOST, або єдиний сайт у БД); інакше null. */
    public function adminSite(): ?ServiceSite
    {
        return $this->branding->site();
    }

    /**
     * Адреса приймача студії для кожного активного міста сайту (у сайту кілька доменів: апекс, піддомени, окремі домени).
     *
     * @return list<array{city: string, slug: string, host: string, apex: bool, studio_api_url: string}>
     */
    public function studioUrls(ServiceSite $site): array
    {
        $out = [];
        foreach ($this->cities->findAllActiveBySite($site) as $city) {
            $host = strtolower(trim($city->getHost()));
            if ($host === '') {
                continue;
            }
            $out[] = ['city' => $city->name('uk'), 'slug' => $city->getSlug(), 'host' => $host, 'apex' => $city->isApex(), 'studio_api_url' => 'https://' . $host . self::STUDIO_PATH];
        }

        return $out;
    }

    /**
     * Усе, що треба менеджеру чи агенту про один сайт: slug для шляхів REST/MCP, адреси, готова команда для Claude Code.
     *
     * @return array<string, mixed>
     */
    public function forSite(ServiceSite $site): array
    {
        return [
            'site' => $site->getSlug(),
            'name' => $site->getName(),
            'admin_api_url' => $this->adminApiUrl(),
            'site_api_url' => $this->adminApiUrl() . '/' . $site->getSlug(),
            'mcp_url' => $this->mcpUrl(),
            'studio' => $this->studioUrls($site),
        ];
    }

    /**
     * Блок `endpoints` для /me і MCP `me`: де що працює + адреси по сайтах.
     *
     * @param list<ServiceSite> $sites
     *
     * @return array<string, mixed>
     */
    public function describe(array $sites): array
    {
        return [
            'admin_host' => $this->adminHost(),
            'admin_url' => $this->adminUrl(),
            'admin_api_url' => $this->adminApiUrl(),
            'me_url' => $this->meUrl(),
            'mcp_url' => $this->mcpUrl(),
            'docs_url' => self::DOCS_URL,
            'rules' => [
                'admin_api' => 'REST ' . self::ADMIN_API_PATH . ' і MCP ' . self::MCP_PATH . ' — лише на хості адмінки ' . $this->adminHost() . '; на доменах сайту вони відповідають 404 wrong_host',
                'studio' => 'Приймач content-studio ' . self::STUDIO_PATH . ' — лише на доменах міст сайту (studio нижче); на хості адмінки — 404 wrong_host',
            ],
            'sites' => array_map(fn (ServiceSite $s): array => $this->forSite($s), $sites),
        ];
    }

    /** Команда для Claude Code з плейсхолдером токена (значення токена сюди не потрапляє). */
    public function claudeMcpCommand(string $name, bool $powershell = false): string
    {
        return sprintf('claude mcp add --transport http %s %s --header "Authorization: Bearer %s"', $name, $this->mcpUrl(), $powershell ? '$env:SVC_TOKEN' : '$SVC_TOKEN');
    }
}
