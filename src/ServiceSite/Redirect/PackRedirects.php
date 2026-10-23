<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

use App\Entity\ServiceSite;
use App\ServiceSite\LegacyUrls;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Перенесення правил паку в БД (дія «Перенести з паку» і команда app:seo:redirects:from-pack): app.legacy_redirects з
 * цільовим шляхом (рядок або {path}) → 301 kind pack на всі міста сайту; app.gone_prefixes → 410 kind pack для кореня
 * префікса (/n/). Ідемпотентно: повторний запуск оновлює правила pack і не чіпає правил manual/auto з тим самим джерелом
 * (вони у звіті «пропущено»). Правила старого формату {city, item, route} залежать від запиту — лишаються в паку.
 * Джерело — у формі, в якій адреса доходить до 404 після гігієни паку: зі слешем, крім файлів (LegacyUrls::trailingSlashTarget).
 * Поки мапа лишається в паку, вона спрацьовує першою (до роутингу) — правила з БД для тих самих адрес стоять у резерві.
 */
final class PackRedirects
{
    /**
     * @param array<string, mixed> $legacyRedirects
     * @param list<string>         $gonePrefixes
     */
    public function __construct(
        private readonly RedirectManager $redirects,
        private readonly LegacyUrls $legacy,
        private readonly Connection $db,
        #[Autowire('%app.legacy_redirects%')] private readonly array $legacyRedirects = [],
        #[Autowire('%app.gone_prefixes%')] private readonly array $gonePrefixes = [],
    ) {
    }

    /** Скільки правил пак дає для перенесення (для підпису дії). @return array{redirects: int, gone: int, legacy: int} */
    public function count(): array
    {
        $legacy = 0;
        $redirects = 0;
        foreach ($this->legacyRedirects as $rule) {
            self::target($rule) === null ? ++$legacy : ++$redirects;
        }

        return ['redirects' => $redirects, 'gone' => \count($this->gonePrefixes), 'legacy' => $legacy];
    }

    /** @return array{created: int, updated: int, skipped: list<string>, errors: list<string>} */
    public function import(ServiceSite $site): array
    {
        $report = ['created' => 0, 'updated' => 0, 'skipped' => [], 'errors' => []];
        $foreign = [];
        foreach ($this->db->fetchAllAssociative('SELECT source, kind FROM service_redirect WHERE site_id = ? AND city_key = 0 AND kind <> ?', [$site->getId(), RedirectKind::Pack->value]) as $row) {
            $foreign[(string) $row['source']] = (string) $row['kind'];
        }
        $rules = [];
        foreach ($this->legacyRedirects as $key => $rule) {
            $target = self::target($rule);
            if ($target === null) {
                $report['skipped'][] = sprintf('%s: правило старого формату (city/item/route) — лишається в паку', (string) $key);
                continue;
            }
            $rules[] = [$this->source((string) $key), RedirectCode::Permanent, $target];
        }
        foreach ($this->gonePrefixes as $prefix) {
            $rules[] = ['/' . trim((string) $prefix, '/') . '/', RedirectCode::Gone, null];
        }
        foreach ($rules as [$source, $code, $target]) {
            $normalized = RedirectPath::normalize($source);
            if ($normalized !== null && isset($foreign[$normalized])) {
                $report['skipped'][] = sprintf('%s: уже є правило «%s» — не чіпаю', $normalized, RedirectKind::from($foreign[$normalized])->label());
                continue;
            }
            try {
                $this->redirects->save($site, null, $source, $code, $target, RedirectKind::Pack) ? ++$report['created'] : ++$report['updated'];
            } catch (InvalidRedirect $e) {
                $report['errors'][] = $source . ': ' . $e->getMessage();
            }
        }

        return $report;
    }

    /** Ключ мапи паку (без слешів по краях) → джерело у формі після гігієни URL. */
    private function source(string $key): string
    {
        $path = '/' . ltrim(LegacyUrls::key($key), '/');

        return $this->legacy->trailingSlashTarget($path) ?? $path;
    }

    /** @param array<string, mixed>|string $rule */
    private static function target(mixed $rule): ?string
    {
        $literal = \is_string($rule) ? $rule : (\is_array($rule) ? (string) ($rule['path'] ?? '') : '');
        $literal = trim($literal);
        if ($literal === '') {
            return null;
        }

        return preg_match('#^https?://#i', $literal) === 1 ? $literal : '/' . ltrim($literal, '/');
    }
}
