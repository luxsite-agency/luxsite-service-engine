<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Журнал 404 сайту (service_not_found), агрегований за містом (хостом) і шляхом. Відомий шлях — один UPDATE за
 * унікальним індексом (без спалювання auto_increment), новий — INSERT (гонка двох перших звернень — ON DUPLICATE KEY).
 * Referer — лише схема, хост і шлях (query може нести персональні дані). Обсяг на сайт: рядки, старші за
 * $retentionDays, і понад $limit (найдавніші за last_hit_at) прибираються — приблизно раз на PRUNE_EVERY нових шляхів
 * і командою app:seo:not-found:prune (cron). Сканування ботів (*.php, wp-*, /.env, /.git…), службові шляхи адмінки й
 * статика рушія у журнал не пишуться — редирект для них не потрібен, а місце в ліміті вони з'їдали б.
 */
final class NotFoundLog
{
    private const int PRUNE_EVERY = 100;
    private const int REFERER_LENGTH = 500;
    private const string PROBE = '~(?:\.(?:php\d?|aspx?|jsp|cgi|env|ini|sql|bak|old|swp|ya?ml|log|git)(?:/|$)|/wp-|/\.|/cgi-bin/|/phpmyadmin|/xmlrpc'
        . '|^/(?:admin|api/|mcp$|login$|logout$|connect/|_|media/cache/|bundles/|themes/|assets/|build/|favicon\.ico$|apple-touch-icon))~i';

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%app.seo_not_found_limit%')] private readonly int $limit,
        #[Autowire('%app.seo_not_found_days%')] private readonly int $retentionDays,
    ) {
    }

    public static function isProbe(string $path): bool
    {
        return preg_match(self::PROBE, $path) === 1;
    }

    public function record(int $siteId, int $cityId, string $path, ?string $referer): void
    {
        if (self::isProbe($path)) {
            return;
        }
        $referer = self::cleanReferer($referer);
        if ($this->db->executeStatement('UPDATE service_not_found SET hits = hits + 1, last_hit_at = NOW(), referer = COALESCE(?, referer) WHERE site_id = ? AND city_id = ? AND path = ?', [$referer, $siteId, $cityId, $path]) > 0) {
            return;
        }
        $this->db->executeStatement(
            'INSERT INTO service_not_found (site_id, city_id, path, hits, first_seen_at, last_hit_at, referer) VALUES (?, ?, ?, 1, NOW(), NOW(), ?) AS new
             ON DUPLICATE KEY UPDATE hits = service_not_found.hits + 1, last_hit_at = new.last_hit_at, referer = COALESCE(new.referer, service_not_found.referer)',
            [$siteId, $cityId, $path, $referer],
        );
        if (random_int(1, self::PRUNE_EVERY) === 1) {
            $this->prune($siteId);
        }
    }

    /** Прибрати за лімітами: один сайт або всі (null). @return int видалених рядків */
    public function prune(?int $siteId = null): int
    {
        $deleted = 0;
        $sites = $siteId === null ? $this->db->fetchFirstColumn('SELECT DISTINCT site_id FROM service_not_found') : [$siteId];
        foreach ($sites as $id) {
            $id = (int) $id;
            $deleted += (int) $this->db->executeStatement('DELETE FROM service_not_found WHERE site_id = ? AND last_hit_at < NOW() - INTERVAL ? DAY', [$id, max(1, $this->retentionDays)]);
            $edge = $this->db->fetchAssociative('SELECT id, last_hit_at FROM service_not_found WHERE site_id = ? ORDER BY last_hit_at DESC, id DESC LIMIT 1 OFFSET ' . max(0, $this->limit - 1), [$id]);
            if ($edge !== false) {
                $deleted += (int) $this->db->executeStatement('DELETE FROM service_not_found WHERE site_id = ? AND (last_hit_at < ? OR (last_hit_at = ? AND id < ?))', [$id, $edge['last_hit_at'], $edge['last_hit_at'], $edge['id']]);
            }
        }

        return $deleted;
    }

    /** Очистити журнал сайту (null — усіх). */
    public function clear(?int $siteId = null): int
    {
        return $siteId === null
            ? (int) $this->db->executeStatement('DELETE FROM service_not_found')
            : (int) $this->db->executeStatement('DELETE FROM service_not_found WHERE site_id = ?', [$siteId]);
    }

    private static function cleanReferer(?string $referer): ?string
    {
        if ($referer === null || !RedirectPath::isAbsoluteUrl($referer)) {
            return null;
        }
        $parts = parse_url($referer);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $clean = strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/');

        return mb_substr($clean, 0, self::REFERER_LENGTH);
    }
}
