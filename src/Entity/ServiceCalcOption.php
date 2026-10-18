<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceCalcOptionRepository;
use App\ServiceSite\CalcOption;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Додаткова опція калькулятора: фіксована ціна (чекбокс, миття духовки)
 * або ціна за одиницю (степер кількості: вікно за стулку, диван за посадкове
 * місце). perUnit = true вмикає степер, unitUk/Ru - підпис одиниці.
 */
#[ORM\Entity(repositoryClass: ServiceCalcOptionRepository::class)]
#[ORM\Table(name: 'service_calc_option')]
#[ORM\Index(columns: ['site_id', 'active', 'position'], name: 'idx_svc_calc_option_site')]
class ServiceCalcOption implements CalcOption, TimestampableInterface
{
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceSite $site = null;

    /** Назва опції («Миття вікон», «Хімчистка дивана») */
    #[ORM\Column(length: 128)]
    private string $nameUk = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $nameRu = null;

    /** Ціна, грн: фіксована або за одиницю (залежно від perUnit) */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $price = '0.00';

    /** true = ціна за одиницю зі степером кількості; false = фіксована ціна з чекбоксом */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $perUnit = false;

    /** Підпис одиниці для perUnit («стулка», «посадкове місце», «м²») */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $unitUk = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $unitRu = null;

    /** Максимальна кількість у степері */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 30])]
    private int $maxQty = 30;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?ServiceSite
    {
        return $this->site;
    }

    public function setSite(?ServiceSite $site): self
    {
        $this->site = $site;

        return $this;
    }

    public function getNameUk(): string
    {
        return $this->nameUk;
    }

    public function setNameUk(string $nameUk): self
    {
        $this->nameUk = $nameUk;

        return $this;
    }

    public function getNameRu(): ?string
    {
        return $this->nameRu;
    }

    public function setNameRu(?string $nameRu): self
    {
        $this->nameRu = $nameRu;

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function isPerUnit(): bool
    {
        return $this->perUnit;
    }

    public function setPerUnit(bool $perUnit): self
    {
        $this->perUnit = $perUnit;

        return $this;
    }

    public function getUnitUk(): ?string
    {
        return $this->unitUk;
    }

    public function setUnitUk(?string $unitUk): self
    {
        $this->unitUk = $unitUk;

        return $this;
    }

    public function getUnitRu(): ?string
    {
        return $this->unitRu;
    }

    public function setUnitRu(?string $unitRu): self
    {
        $this->unitRu = $unitRu;

        return $this;
    }

    public function getMaxQty(): int
    {
        return $this->maxQty;
    }

    public function setMaxQty(int $maxQty): self
    {
        $this->maxQty = $maxQty;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nameUk;
    }
}
