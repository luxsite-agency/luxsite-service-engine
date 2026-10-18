<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Колонки created_at / updated_at: nullable datetime (mutable \DateTime - з datetime_immutable
 * Doctrine падав на flush, див. міграції 20260914). Заповнює TimestampableListener:
 * на persist - обидві, якщо порожні (імпорт може задати свою дату), на update - лише updated_at.
 *
 * Сутність: `class X implements TimestampableInterface { use TimestampsTrait; }`.
 */
trait TimestampsTrait
{
    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?\DateTime $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    protected ?\DateTime $updatedAt = null;

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = self::mutableDate($createdAt);

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = self::mutableDate($updatedAt);

        return $this;
    }

    /** Колонка datetime приймає лише \DateTime; immutable переводимо, щоб виклик не впав на flush. */
    private static function mutableDate(\DateTimeInterface $date): \DateTime
    {
        return $date instanceof \DateTime ? $date : \DateTime::createFromInterface($date);
    }
}
