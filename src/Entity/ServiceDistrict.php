<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceDistrictRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Район міста (сторінка /districts/<slug>/) — скоупиться по місту.
 * Тексти імпорту з Joomla НЕ переносяться (сантехнічні) — генеруються заново.
 */
#[ORM\Entity(repositoryClass: ServiceDistrictRepository::class)]
#[ORM\Table(name: 'service_district')]
#[ORM\UniqueConstraint(name: 'uniq_svc_district_city_slug_uk', columns: ['city_id', 'slug_uk'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_district_city_slug_ru', columns: ['city_id', 'slug_ru'])]
class ServiceDistrict implements TimestampableInterface
{
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCity $city = null;

    /** id матеріалу area в Joomla-джерелі (ідемпотентний імпорт) */
    #[ORM\Column(nullable: true)]
    private ?int $legacyId = null;

    #[ORM\Column(length: 64)]
    private string $slugUk = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $slugRu = null;

    /** Група в акордеоні «Де ми працюємо» (адмінрайон: «Оболонський район») */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $groupUk = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $groupRu = null;

    /** Назва району (H1: «Оболонь») */
    #[ORM\Column(length: 128)]
    private string $nameUk = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $nameRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescRu = null;

    /** Мінімальна вартість виїзду в цьому районі, грн (JSON-LD PropertyValue) */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $minPrice = null;

    /** Вулиці/мікрорайони для акордеона (розділені новим рядком) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subAreasUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $subAreasRu = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /**
     * Головна сторінка групи - офіційний адмінрайон. Такі сторінки виводяться
     * на головній, у хабі й на сторінках послуг; масиви лінкуються з них.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $isPrimary = false;

    /** Обкладинка району; порожньо - шаблон бере фото з загального пулу. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAlt = null;

    /**
     * Питання-відповіді локації: [{q, a}]. Дають FAQPage-розмітку й закривають
     * запити, з якими люди приходять на районну сторінку.
     *
     * @var array<int, array{q: string, a: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqUk = null;

    /** @var array<int, array{q: string, a: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqRu = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getLegacyId(): ?int
    {
        return $this->legacyId;
    }

    public function setLegacyId(?int $legacyId): self
    {
        $this->legacyId = $legacyId;

        return $this;
    }

    public function getSlugUk(): string
    {
        return $this->slugUk;
    }

    public function setSlugUk(string $slugUk): self
    {
        $this->slugUk = $slugUk;

        return $this;
    }

    public function getSlugRu(): ?string
    {
        return $this->slugRu;
    }

    public function setSlugRu(?string $slugRu): self
    {
        $this->slugRu = $slugRu;

        return $this;
    }

    public function getGroupUk(): ?string
    {
        return $this->groupUk;
    }

    public function setGroupUk(?string $groupUk): self
    {
        $this->groupUk = $groupUk;

        return $this;
    }

    public function getGroupRu(): ?string
    {
        return $this->groupRu;
    }

    public function setGroupRu(?string $groupRu): self
    {
        $this->groupRu = $groupRu;

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

    public function getLeadUk(): ?string
    {
        return $this->leadUk;
    }

    public function setLeadUk(?string $leadUk): self
    {
        $this->leadUk = $leadUk;

        return $this;
    }

    public function getLeadRu(): ?string
    {
        return $this->leadRu;
    }

    public function setLeadRu(?string $leadRu): self
    {
        $this->leadRu = $leadRu;

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

    public function getMinPrice(): ?int
    {
        return $this->minPrice;
    }

    public function setMinPrice(?int $minPrice): self
    {
        $this->minPrice = $minPrice;

        return $this;
    }

    public function getSubAreasUk(): ?string
    {
        return $this->subAreasUk;
    }

    public function setSubAreasUk(?string $subAreasUk): self
    {
        $this->subAreasUk = $subAreasUk;

        return $this;
    }

    public function getSubAreasRu(): ?string
    {
        return $this->subAreasRu;
    }

    public function setSubAreasRu(?string $subAreasRu): self
    {
        $this->subAreasRu = $subAreasRu;

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

    /** Слаг за локаллю з фолбеком ru→uk */
    public function slug(string $locale): string
    {
        return (string) $this->loc('slug', $locale);
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): self
    {
        $this->isPrimary = $isPrimary;

        return $this;
    }

    /** @return array<int, array{q: string, a: string}>|null */
    public function getFaqUk(): ?array
    {
        return $this->faqUk;
    }

    public function setFaqUk(?array $faqUk): self
    {
        $this->faqUk = $faqUk;

        return $this;
    }

    /** @return array<int, array{q: string, a: string}>|null */
    public function getFaqRu(): ?array
    {
        return $this->faqRu;
    }

    public function setFaqRu(?array $faqRu): self
    {
        $this->faqRu = $faqRu;

        return $this;
    }


    public function getImageName(): ?string
    {
        return $this->imageName;
    }

    public function setImageName(?string $imageName): self
    {
        $this->imageName = $imageName;

        return $this;
    }

    public function getImageAlt(): ?string
    {
        return $this->imageAlt;
    }

    public function setImageAlt(?string $imageAlt): self
    {
        $this->imageAlt = $imageAlt;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nameUk;
    }
}
