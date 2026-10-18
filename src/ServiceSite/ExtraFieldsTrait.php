<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Додаткові поля запису (рішення власника 13.10.2026): набір полів задає пак (app.custom_fields — див. CustomFields),
 * значення живуть тут, у JSON-колонці extra: {"<ключ>": {"uk": "…", "ru": "…"}}. Нове поле = рядок у паку, без міграцій.
 * Порожнє ru = українське значення (як loc()).
 */
trait ExtraFieldsTrait
{
    /** @var array<string, array<string, string>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $extra = null;

    /** @return array<string, array<string, string>>|null */
    public function getExtra(): ?array
    {
        return $this->extra;
    }

    /** @param array<string, array<string, string>>|null $extra */
    public function setExtra(?array $extra): self
    {
        $clean = [];
        foreach ((array) $extra as $key => $langs) {
            $vals = [];
            foreach ((array) $langs as $lang => $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $vals[(string) $lang] = $value;
                }
            }
            if ($vals !== []) {
                $clean[(string) $key] = $vals;
            }
        }
        $this->extra = $clean === [] ? null : $clean;

        return $this;
    }

    /** Значення поля мовою сторінки (ru → uk, якщо ru порожнє); null = не заповнено. */
    public function extraValue(string $key, string $locale): ?string
    {
        $vals = $this->extra[$key] ?? null;
        if (!\is_array($vals)) {
            return null;
        }
        $lang = $locale === 'ru' ? 'ru' : 'uk';

        return $vals[$lang] ?? $vals['uk'] ?? null;
    }
}
