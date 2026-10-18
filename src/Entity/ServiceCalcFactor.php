<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceCalcFactorRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Коефіцієнт калькулятора: варіант у групі (тип обʼєкта, стан забруднення,
 * терміновість, поверх/ліфт, вихідний день). Кожна група рендериться радіо-чипсами,
 * обраний варіант множить базову вартість. isDefault = передобраний варіант групи
 * (зазвичай з множником 1.00).
 */
#[ORM\Entity(repositoryClass: ServiceCalcFactorRepository::class)]
#[ORM\Table(name: 'service_calc_factor')]
#[ORM\Index(columns: ['site_id', 'group_code', 'active'], name: 'idx_svc_calc_factor_site')]
class ServiceCalcFactor implements TimestampableInterface
{
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    /** Групи коефіцієнтів; порядок масиву = порядок блоків на сторінці */
    public const GROUPS = ['object_type', 'dirt_level', 'urgency', 'floor', 'weekend'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceSite $site = null;

    /** Код групи (див. self::GROUPS); підпис групи - у translations calc.group.* */
    #[ORM\Column(length: 32)]
    private string $groupCode = 'object_type';

    /** Назва варіанта («Квартира», «Сильне забруднення») */
    #[ORM\Column(length: 128)]
    private string $nameUk = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $nameRu = null;

    /** Множник вартості (1.00 = без надбавки, 1.25 = +25%) */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, options: ['default' => '1.00'])]
    private string $multiplier = '1.00';

    /** Передобраний варіант групи в калькуляторі */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isDefault = false;

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

    public function getGroupCode(): string
    {
        return $this->groupCode;
    }

    public function setGroupCode(string $groupCode): self
    {
        $this->groupCode = $groupCode;

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

    public function getMultiplier(): string
    {
        return $this->multiplier;
    }

    public function setMultiplier(string $multiplier): self
    {
        $this->multiplier = $multiplier;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;

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
        return $this->groupCode . ': ' . $this->nameUk . ' x' . $this->multiplier;
    }
}
