<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Repository\ServiceCityRepository;
use Doctrine\DBAL\Connection;

/**
 * CSV менеджера редиректів, роздільник «;»: імпорт — source;code;target[;city] (city — slug міста сайту, порожньо = усі
 * міста; рядок-заголовок «source;…» пропускається, зайві колонки ігноруються, тож експорт імпортується назад як є).
 * Кожен рядок — RedirectManager::save() по черзі: перевірка циклів бачить і правила з попередніх рядків файлу. Хибні
 * рядки не зупиняють імпорт — вони у звіті.
 */
final class RedirectCsv
{
    public const array HEADER = ['source', 'code', 'target', 'city', 'kind', 'hits', 'last_hit_at'];
    /** Синхронний імпорт з адмінки: кожен рядок — перевірка живої адреси, ланцюга й запис; більше — 504 і частковий імпорт. */
    public const int WEB_MAX_ROWS = 5000;

    public function __construct(private readonly RedirectManager $redirects, private readonly ServiceCityRepository $cities, private readonly Connection $db)
    {
    }

    /**
     * @param int|null $maxRows null — без ліміту (консольна команда)
     *
     * @return array{created: int, updated: int, errors: list<array{line: int, message: string}>}
     */
    public function import(ServiceSite $site, string $file, ?int $maxRows = self::WEB_MAX_ROWS, bool $allowExternal = true): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Не вдалося прочитати файл');
        }
        $cities = [];
        foreach ($this->cities->findBy(['site' => $site]) as $city) {
            $cities[$city->getSlug()] = $city;
        }
        $report = ['created' => 0, 'updated' => 0, 'errors' => []];
        $line = 0;
        $rows = 0;
        try {
            while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
                ++$line;
                if ($line === 1 && isset($row[0])) {
                    $row[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                }
                $row = array_map(static fn (?string $v): string => trim((string) $v), $row);
                if ($row === [''] || array_filter($row, static fn (string $v): bool => $v !== '') === []) {
                    continue;
                }
                if ($line === 1 && strtolower($row[0]) === 'source') {
                    continue;
                }
                if ($maxRows !== null && ++$rows > $maxRows) {
                    $report['errors'][] = ['line' => $line, 'message' => sprintf('Понад %d рядків — решту файлу пропущено (більші файли — app:seo:redirects:import)', $maxRows)];
                    break;
                }
                if (!mb_check_encoding(implode(';', $row), 'UTF-8')) {
                    $report['errors'][] = ['line' => $line, 'message' => 'Рядок не в UTF-8: збережіть файл як «CSV UTF-8»'];
                    continue;
                }
                $code = RedirectCode::tryFrom((int) ($row[1] ?? 0));
                if ($code === null || !ctype_digit($row[1] ?? '')) {
                    $report['errors'][] = ['line' => $line, 'message' => sprintf('Код «%s»: дозволено 301, 302, 404, 410', $row[1] ?? '')];
                    continue;
                }
                $citySlug = $row[3] ?? '';
                $city = null;
                if ($citySlug !== '') {
                    $city = $cities[$citySlug] ?? null;
                    if (!$city instanceof ServiceCity) {
                        $report['errors'][] = ['line' => $line, 'message' => sprintf('Місто «%s»: нема такого на сайті (slug міста або порожньо = усі міста)', $citySlug)];
                        continue;
                    }
                }
                try {
                    $this->redirects->save($site, $city, $row[0], $code, $row[2] ?? null, allowExternal: $allowExternal) ? ++$report['created'] : ++$report['updated'];
                } catch (InvalidRedirect $e) {
                    $report['errors'][] = ['line' => $line, 'message' => $e->getMessage()];
                }
            }
        } finally {
            fclose($handle);
        }

        return $report;
    }

    /** @param resource $out */
    public function export(ServiceSite $site, $out): void
    {
        fwrite($out, "\xEF\xBB\xBF"); // Excel відкриває UTF-8 правильно лише з BOM; імпорт його знімає
        fputcsv($out, self::HEADER, ';', '"', '');
        $sql = 'SELECT r.source, r.code, r.target, c.slug, r.kind, r.hits, r.last_hit_at FROM service_redirect r LEFT JOIN service_city c ON c.id = r.city_id
                WHERE r.site_id = ? ORDER BY r.source, r.city_key';
        foreach ($this->db->iterateNumeric($sql, [$site->getId()]) as $row) {
            fputcsv($out, array_map(static fn (mixed $v): string => (string) $v, $row), ';', '"', '');
        }
    }
}
