<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use App\AdminApi\Auth\ApiTokenContext;
use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceSiteRepository;
use App\ServiceSite\Redirect\InvalidRedirect;
use App\ServiceSite\Redirect\RedirectCode;
use App\ServiceSite\Redirect\RedirectKind;
use App\ServiceSite\Redirect\RedirectManager;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Редиректи й журнал 404 для менеджерів і AI-агентів (MCP) — одне ядро для REST (/api/admin/v1/{site}/redirects) і MCP
 * (redirects_list, redirect_create, redirect_delete, not_found_list). Ті самі правила, що в адмінці (RedirectManager):
 * нормалізація, злиття ланцюгів, відмова на цикл чи живу адресу; чужий домен у цілі — лише власнику з ROLE_ADMIN.
 * Дозволи — content:read на читання, content:write на запис (ScopeVoter).
 */
final class RedirectService
{
    public function __construct(
        private readonly RedirectManager $redirects,
        private readonly Connection $db,
        private readonly ServiceSiteRepository $sites,
        private readonly ServiceCityRepository $cities,
        private readonly ApiTokenContext $context,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $filters city (slug), code (301|302|404|410), kind (manual|auto|pack), q (підрядок джерела/цілі)
     *
     * @return array<string, mixed>
     */
    public function list(string $siteSlug, array $filters = []): array
    {
        $this->requireScope('scope:content:read');
        $site = $this->site($siteSlug);
        $sql = 'SELECT r.id, r.source, r.target, r.code, r.kind, c.slug AS city, r.hits, r.last_hit_at, r.created_at
                FROM service_redirect r LEFT JOIN service_city c ON c.id = r.city_id WHERE r.site_id = ?';
        $params = [$site->getId()];
        if (($city = trim((string) ($filters['city'] ?? ''))) !== '') {
            $sql .= ' AND c.slug = ?';
            $params[] = $city;
        }
        if (($code = (int) ($filters['code'] ?? 0)) > 0) {
            $sql .= ' AND r.code = ?';
            $params[] = $code;
        }
        if (($kind = trim((string) ($filters['kind'] ?? ''))) !== '') {
            $sql .= ' AND r.kind = ?';
            $params[] = $kind;
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $sql .= ' AND (r.source LIKE ? OR r.target LIKE ?)';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $rows = array_map(self::row(...), $this->db->fetchAllAssociative($sql . ' ORDER BY r.id DESC LIMIT 5000', $params));

        return ['site' => $site->getSlug(), 'count' => \count($rows), 'items' => $rows];
    }

    /**
     * @param array<string, mixed> $data source, code (301 типово), target (для 301/302), city (slug або порожньо = усі міста)
     *
     * @return array{0: array<string, mixed>, 1: bool} правило й чи воно нове
     */
    public function create(string $siteSlug, array $data): array
    {
        $this->requireScope('scope:content:write');
        $site = $this->site($siteSlug);
        $source = trim((string) ($data['source'] ?? ''));
        if ($source === '') {
            throw new ApiProblem('Потрібне поле source: стара адреса (шлях від кореня або повний URL)', 422);
        }
        $codeValue = $data['code'] ?? 301;
        $code = \is_int($codeValue) || (\is_string($codeValue) && ctype_digit($codeValue)) ? RedirectCode::tryFrom((int) $codeValue) : null;
        if ($code === null) {
            throw new ApiProblem('code: дозволено 301, 302, 404, 410', 422);
        }
        $city = $this->city($site, $data['city'] ?? null);
        $target = isset($data['target']) && !\is_array($data['target']) ? (string) $data['target'] : null;
        try {
            $created = $this->redirects->save($site, $city, $source, $code, $target, RedirectKind::Manual, $this->auth->isGranted('ROLE_ADMIN'));
        } catch (InvalidRedirect $e) {
            throw new ApiProblem($e->getMessage(), 422);
        }
        $row = $this->db->fetchAssociative(
            'SELECT r.id, r.source, r.target, r.code, r.kind, c.slug AS city, r.hits, r.last_hit_at, r.created_at FROM service_redirect r LEFT JOIN service_city c ON c.id = r.city_id
             WHERE r.site_id = ? AND r.city_key = ? AND r.source = ?',
            [$site->getId(), RedirectManager::key($city), \App\ServiceSite\Redirect\RedirectPath::normalize($source)],
        );
        $row = $row === false ? [] : self::row($row);
        $this->audit($created ? 'redirect.create' : 'redirect.update', $site, (int) ($row['id'] ?? 0), $row['source'] ?? $source);

        return [$row, $created];
    }

    public function delete(string $siteSlug, int $id): void
    {
        $this->requireScope('scope:content:write');
        $site = $this->site($siteSlug);
        $source = $this->db->fetchOne('SELECT source FROM service_redirect WHERE id = ? AND site_id = ?', [$id, $site->getId()]);
        if ($source === false) {
            throw new ApiProblem('Правило не знайдено: ' . $id, 404);
        }
        $this->redirects->delete($id);
        $this->audit('redirect.delete', $site, $id, (string) $source);
    }

    /**
     * @param array<string, mixed> $filters city (slug), q (підрядок адреси), limit (до 1000, типово 200)
     *
     * @return array<string, mixed>
     */
    public function notFound(string $siteSlug, array $filters = []): array
    {
        $this->requireScope('scope:content:read');
        $site = $this->site($siteSlug);
        $sql = 'SELECT n.id, n.path, c.slug AS city, c.host, n.hits, n.first_seen_at, n.last_hit_at, n.referer
                FROM service_not_found n JOIN service_city c ON c.id = n.city_id WHERE n.site_id = ?';
        $params = [$site->getId()];
        if (($city = trim((string) ($filters['city'] ?? ''))) !== '') {
            $sql .= ' AND c.slug = ?';
            $params[] = $city;
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $sql .= ' AND n.path LIKE ?';
            $params[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $limit = min(1000, max(1, (int) ($filters['limit'] ?? 200)));
        $rows = [];
        foreach ($this->db->fetchAllAssociative($sql . ' ORDER BY n.hits DESC, n.last_hit_at DESC LIMIT ' . $limit, $params) as $r) {
            $rows[] = ['id' => (int) $r['id'], 'path' => (string) $r['path'], 'city' => (string) $r['city'], 'host' => (string) $r['host'], 'hits' => (int) $r['hits'],
                'first_seen_at' => (string) $r['first_seen_at'], 'last_hit_at' => (string) $r['last_hit_at'], 'referer' => $r['referer'] === null ? null : (string) $r['referer']];
        }

        return ['site' => $site->getSlug(), 'count' => \count($rows), 'items' => $rows];
    }

    /** @param array<string, mixed> $r */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['id'], 'source' => (string) $r['source'], 'target' => $r['target'] === null ? null : (string) $r['target'], 'code' => (int) $r['code'],
            'kind' => (string) $r['kind'], 'city' => $r['city'] === null ? null : (string) $r['city'], 'hits' => (int) $r['hits'],
            'last_hit_at' => $r['last_hit_at'] === null ? null : (string) $r['last_hit_at'], 'created_at' => (string) $r['created_at']];
    }

    private function requireScope(string $attribute): void
    {
        if (!$this->auth->isGranted($attribute)) {
            throw new ApiProblem(sprintf('Токену бракує дозволу %s або власнику — ролі для нього', substr($attribute, 6)), 403);
        }
    }

    private function site(string $slug): ServiceSite
    {
        return $this->sites->findActiveBySlug($slug) ?? throw new ApiProblem('Сайт не знайдено: ' . $slug, 404);
    }

    private function city(ServiceSite $site, mixed $slug): ?ServiceCity
    {
        $slug = \is_scalar($slug) ? trim((string) $slug) : '';
        if ($slug === '') {
            return null;
        }

        return $this->cities->findOneBy(['site' => $site, 'slug' => $slug]) ?? throw new ApiProblem('Місто не знайдено на сайті: ' . $slug . ' (slug міста з me або порожньо = усі міста)', 422);
    }

    private function audit(string $action, ServiceSite $site, int $id, string $source): void
    {
        $token = $this->context->current();
        $this->logger->info('admin_api.' . $action, [
            'site' => $site->getSlug(), 'id' => $id, 'source' => $source,
            'user_id' => $token?->getUser()->getId(), 'user' => $token?->getUser()->getEmail(),
            'token_id' => $token?->getId(), 'token_prefix' => $token?->getPrefix(),
        ]);
    }
}
