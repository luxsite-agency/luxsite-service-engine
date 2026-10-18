<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServicePriceRepository;
use App\ServiceSite\CalcOption;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Рядок прайсу. Скоупиться по МІСТУ (ціни в містах різні) - аналог
 * aim_apl_prices (service+city) у Joomla/Seblod.
 * Або числова price (з опційним префіксом «від»), або довільний priceText.
 *
 * Єдине джерело цін: цей самий рядок показується в таблиці на сторінці
 * послуги і, якщо стоїть inCalculator, пропонується в калькуляторі. Раніше
 * це були дві незалежні таблиці, які неминуче розходились.
 *
 * item може бути порожнім - позиція на кшталт «Озонування» належить прайсу
 * загалом, а не окремій послузі, і живе тільки в калькуляторі.
 */
#[ORM\Entity(repositoryClass: ServicePriceRepository::class)]
#[ORM\Table(name: 'service_price')]
#[ORM\Index(columns: ['item_id', 'city_id', 'active'], name: 'idx_svc_price_item_city')]
#[ORM\Index(columns: ['city_id', 'in_calculator', 'active', 'position'], name: 'idx_svc_price_calc')]
class ServicePrice implements CalcOption, TimestampableInterface
{
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ServiceItem $item = null;

    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCity $city = null;

    /** id запису в старій CMS (ідемпотентний імпорт app:joomla:import); null = створено в рушії */
    #[ORM\Column(nullable: true)]
    private ?int $legacyId = null;

    /** Назва позиції («Заміна розетки») */
    #[ORM\Column(length: 255)]
    private string $nameUk = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nameRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descRu = null;

    /** Числова ціна в грн; null = дивитись priceText */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $price = null;

    /** Префікс перед числом («від»); порожньо = точна ціна */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $pricePrefix = null;

    /** Довільний текст замість числа («За домовленістю») */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $priceTextUk = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $priceTextRu = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** Показувати позицію серед додаткових послуг калькулятора */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $inCalculator = false;

    /** У калькуляторі рахується за кількість (м², шт), а не разово */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $perUnit = false;

    /** Одиниця виміру для кількості («м²», «шт», «посадкове місце») */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $unitUk = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $unitRu = null;

    /** Стеля кількості в полі вводу калькулятора */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 30])]
    private int $maxQty = 30;

    public function getLegacyId(): ?int
    {
        return $this->legacyId;
    }

    public function setLegacyId(?int $legacyId): self
    {
        $this->legacyId = $legacyId;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): ?ServiceItem
    {
        return $this->item;
    }

    public function setItem(?ServiceItem $item): self
    {
        $this->item = $item;

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

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getPricePrefix(): ?string
    {
        return $this->pricePrefix;
    }

    public function setPricePrefix(?string $pricePrefix): self
    {
        $this->pricePrefix = $pricePrefix;

        return $this;
    }

    public function getPriceTextUk(): ?string
    {
        return $this->priceTextUk;
    }

    public function setPriceTextUk(?string $priceTextUk): self
    {
        $this->priceTextUk = $priceTextUk;

        return $this;
    }

    public function getPriceTextRu(): ?string
    {
        return $this->priceTextRu;
    }

    public function setPriceTextRu(?string $priceTextRu): self
    {
        $this->priceTextRu = $priceTextRu;

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

    public function isInCalculator(): bool
    {
        return $this->inCalculator;
    }

    public function setInCalculator(bool $inCalculator): self
    {
        $this->inCalculator = $inCalculator;

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

    public function __toString(): string
    {
        return $this->nameUk;
    }
}
