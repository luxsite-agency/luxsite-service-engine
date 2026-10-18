<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceCalcTypeCityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ставка калькулятора для конкретного міста. Партнерський прайс різниться:
 * Київ дорожчий за Харків, Дніпро й Одесу рівно на 10%, і показувати всім
 * одну суму означає обіцяти те, чого менеджер не підтвердить.
 *
 * Рядка може не бути - тоді діє базова ставка самого ServiceCalcType.
 * Порожнє поле теж не помилка: перевизначити можна лише ціну за м², лишивши
 * мінімальний чек базовим, і навпаки.
 */
#[ORM\Entity(repositoryClass: ServiceCalcTypeCityRepository::class)]
#[ORM\Table(name: 'service_calc_type_city')]
#[ORM\UniqueConstraint(name: 'uniq_calc_type_city', columns: ['type_id', 'city_id'])]
#[ORM\Index(columns: ['type_id'], name: 'IDX_calc_type_city_type')]
#[ORM\Index(columns: ['city_id'], name: 'IDX_calc_type_city_city')]
class ServiceCalcTypeCity implements TimestampableInterface
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceCalcType::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCalcType $type = null;

    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCity $city = null;

    /** Ціна за м² для цього міста, грн */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $pricePerSqm = null;

    /** Мінімальний чек для цього міста, грн */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $minPrice = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?ServiceCalcType
    {
        return $this->type;
    }

    public function setType(?ServiceCalcType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getCity(): ?ServiceCity
    {
        return $this->city;
    }

    public function setCity(?ServiceCity $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getPricePerSqm(): ?string
    {
        return $this->pricePerSqm;
    }

    public function setPricePerSqm(?string $pricePerSqm): self
    {
        $this->pricePerSqm = $pricePerSqm;

        return $this;
    }

    public function getMinPrice(): ?string
    {
        return $this->minPrice;
    }

    public function setMinPrice(?string $minPrice): self
    {
        $this->minPrice = $minPrice;

        return $this;
    }

    /**
     * Ці два поля не зберігаються - вони для списку в адмінці, щоб модератор
     * бачив, від чого саме відхиляється ціна, і не звіряв рядки очима.
     */
    public function getBaseSummary(): string
    {
        if ($this->type === null) {
            return '';
        }

        return self::money($this->type->getBasePricePerSqm()) . ' грн/м², мін. '
            . self::money($this->type->getBaseMinPrice()) . ' грн';
    }

    public function getDeviation(): string
    {
        $base = (float) ($this->type?->getBasePricePerSqm() ?? 0);
        $own = $this->pricePerSqm !== null ? (float) $this->pricePerSqm : null;

        if ($own === null) {
            return 'як базова';
        }
        if ($base <= 0.0) {
            return 'своя ціна';
        }

        $percent = (int) round(($own - $base) / $base * 100);

        return match (true) {
            $percent > 0 => '+' . $percent . '% до базової',
            $percent < 0 => $percent . '% до базової',
            default => 'дорівнює базовій',
        };
    }

    /** «132.00» -> «132», «3300.00» -> «3 300» */
    private static function money(string $value): string
    {
        return number_format((float) $value, 0, '.', ' ');
    }

    public function __toString(): string
    {
        return ($this->type?->getNameUk() ?? '?') . ' - ' . ($this->city?->getNameNomUk() ?? '?');
    }
}
