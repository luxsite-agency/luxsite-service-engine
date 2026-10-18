<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceCategoryCityRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Тексти категорії послуг під конкретне місто (дзеркало ServiceItemCity, 10.2026):
 * сторінки /services/<категорія>/ на міських хостах інакше дублюють апекс. Порожнє поле = з категорії.
 */
#[ORM\Entity(repositoryClass: ServiceCategoryCityRepository::class)]
#[ORM\Table(name: 'service_category_city')]
#[ORM\UniqueConstraint(name: 'uniq_category_city', columns: ['category_id', 'city_id'])]
#[ORM\Index(columns: ['category_id'], name: 'IDX_SCC_CATEGORY')]
#[ORM\Index(columns: ['city_id'], name: 'IDX_SCC_CITY')]
class ServiceCategoryCity implements TimestampableInterface
{
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCategory $category = null;

    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCity $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $desc2Uk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $desc2Ru = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $desc3Uk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $desc3Ru = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?ServiceCategory
    {
        return $this->category;
    }

    public function setCategory(?ServiceCategory $category): self
    {
        $this->category = $category;

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

    public function getTitleUk(): ?string
    {
        return $this->titleUk;
    }

    public function setTitleUk(?string $titleUk): self
    {
        $this->titleUk = $titleUk;

        return $this;
    }

    public function getTitleRu(): ?string
    {
        return $this->titleRu;
    }

    public function setTitleRu(?string $titleRu): self
    {
        $this->titleRu = $titleRu;

        return $this;
    }

    public function getMetaTitleUk(): ?string
    {
        return $this->metaTitleUk;
    }

    public function setMetaTitleUk(?string $metaTitleUk): self
    {
        $this->metaTitleUk = $metaTitleUk;

        return $this;
    }

    public function getMetaTitleRu(): ?string
    {
        return $this->metaTitleRu;
    }

    public function setMetaTitleRu(?string $metaTitleRu): self
    {
        $this->metaTitleRu = $metaTitleRu;

        return $this;
    }

    public function getMetaDescUk(): ?string
    {
        return $this->metaDescUk;
    }

    public function setMetaDescUk(?string $metaDescUk): self
    {
        $this->metaDescUk = $metaDescUk;

        return $this;
    }

    public function getMetaDescRu(): ?string
    {
        return $this->metaDescRu;
    }

    public function setMetaDescRu(?string $metaDescRu): self
    {
        $this->metaDescRu = $metaDescRu;

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

    public function getDesc2Uk(): ?string
    {
        return $this->desc2Uk;
    }

    public function setDesc2Uk(?string $desc2Uk): self
    {
        $this->desc2Uk = $desc2Uk;

        return $this;
    }

    public function getDesc2Ru(): ?string
    {
        return $this->desc2Ru;
    }

    public function setDesc2Ru(?string $desc2Ru): self
    {
        $this->desc2Ru = $desc2Ru;

        return $this;
    }

    public function getDesc3Uk(): ?string
    {
        return $this->desc3Uk;
    }

    public function setDesc3Uk(?string $desc3Uk): self
    {
        $this->desc3Uk = $desc3Uk;

        return $this;
    }

    public function getDesc3Ru(): ?string
    {
        return $this->desc3Ru;
    }

    public function setDesc3Ru(?string $desc3Ru): self
    {
        $this->desc3Ru = $desc3Ru;

        return $this;
    }

    public function __toString(): string
    {
        return ($this->category?->getTitleUk() ?? '?') . ' / ' . ($this->city?->getName('uk') ?? '?');
    }
}
