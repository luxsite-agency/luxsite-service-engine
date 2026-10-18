<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Сутність із полями «створено / оновлено». Самі поля дає TimestampsTrait,
 * а заповнює їх TimestampableListener на persist і update - без Gedmo й чужих бібліотек.
 */
interface TimestampableInterface
{
    public function getCreatedAt(): ?\DateTimeInterface;

    public function setCreatedAt(\DateTimeInterface $createdAt): static;

    public function getUpdatedAt(): ?\DateTimeInterface;

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static;
}
