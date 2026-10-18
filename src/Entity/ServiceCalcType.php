<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceCalcTypeRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Тип прибирання в калькуляторі (підтримуюче / генеральне / після ремонту).
 * Базова ціна за м² + мінімальний чек. Скоупиться по САЙТУ (бренду), а місто
 * за потреби перебиває ставку через ServiceCalcTypeCity.
 *
 * Перевизначення накладається в репозиторії і живе тільки в памʼяті запиту:
 * поля, які читає Doctrine, лишаються базовими, тож випадковий flush нічого
 * не перепише. Геттери при цьому віддають міську ціну, тому шаблони,
 * CalcPricer і адмінка працюють без жодних змін.
 */
#[ORM\Entity(repositoryClass: ServiceCalcTypeRepository::class)]
#[ORM\Table(name: 'service_calc_type')]
#[ORM\Index(columns: ['site_id', 'active', 'position'], name: 'idx_svc_calc_type_site')]
class ServiceCalcType implements TimestampableInterface
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

    /** Назва типу («Генеральне прибирання») */
    #[ORM\Column(length: 128)]
    private string $nameUk = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $nameRu = null;

    /** Короткий підпис під назвою («пил, підлога, санвузол, кухня») */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $descUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $descRu = null;

    /** Базова ціна за м², грн */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $pricePerSqm = '0.00';

    /** Мінімальний чек, грн (підсумок не опускається нижче) */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $minPrice = '0.00';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** Не в БД: ставка міста, накладена на час запиту */
    private ?string $cityPricePerSqm = null;

    /** Не в БД: мінімальний чек міста, накладений на час запиту */
    private ?string $cityMinPrice = null;

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

    public function getDescUk(): ?string
    {
        return $this->descUk;
    }

    public function setDescUk(?string $descUk): self
    {
        $this->descUk = $descUk;

        return $this;
    }

    public function getDescRu(): ?string
    {
        return $this->descRu;
    }

    public function setDescRu(?string $descRu): self
    {
        $this->descRu = $descRu;

        return $this;
    }

    public function getPricePerSqm(): string
    {
        return $this->cityPricePerSqm ?? $this->pricePerSqm;
    }

    /** Базова ставка бренду, без огляду на місто - потрібна адмінці */
    public function getBasePricePerSqm(): string
    {
        return $this->pricePerSqm;
    }

    public function setPricePerSqm(string $pricePerSqm): self
    {
        $this->pricePerSqm = $pricePerSqm;

        return $this;
    }

    public function getMinPrice(): string
    {
        return $this->cityMinPrice ?? $this->minPrice;
    }

    /** Базовий мінімальний чек бренду, без огляду на місто */
    public function getBaseMinPrice(): string
    {
        return $this->minPrice;
    }

    public function setMinPrice(string $minPrice): self
    {
        $this->minPrice = $minPrice;

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

    /** Накладає ціни міста. Викликається репозиторієм, не адмінкою. */
    public function applyCityPrices(?string $pricePerSqm, ?string $minPrice): self
    {
        $this->cityPricePerSqm = $pricePerSqm;
        $this->cityMinPrice = $minPrice;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nameUk;
    }
}
