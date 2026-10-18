<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceSiteRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Сервісний сайт-бренд (ніша): електрики, клінінг, сантехніки - один рушій, пак на бренд.
 * Профіль «сервісних сайтів» живе поруч із міськими сайтами (City) і
 * резолвиться по Host окремим ServiceHostResolver.
 */
#[ORM\Entity(repositoryClass: ServiceSiteRepository::class)]
#[ORM\Table(name: 'service_site')]
class ServiceSite implements TimestampableInterface
{
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Латинський ідентифікатор ніші; збігається з dev-хостом <slug>.localhost */
    #[ORM\Column(length: 64, unique: true)]
    private string $slug;

    #[ORM\Column(length: 128)]
    private string $name;

    /** Апекс-домен бренду (example.com.ua) — на ньому живе головне місто */
    #[ORM\Column(length: 128, unique: true)]
    private string $apexDomain;

    /**
     * Коротка назва бренду для текстів (власник 18.10.2026: «Відгуки клієнтів про компанію %site%»). Порожньо —
     * береться параметр паку app.brand_name, далі назва сайту. Потрібна, коли назва — загальне слово
     * («Електрики» → «Електрики» в лапках) або різниться за мовою.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $brandNameUk = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $brandNameRu = null;

    /**
     * Підтип LocalBusiness для JSON-LD (Electrician, Plumber, HouseCleaning...).
     * Платформа мультинішева — тип схеми не хардкодиться в шаблонах.
     */
    #[ORM\Column(length: 64, options: ['default' => 'LocalBusiness'])]
    private string $schemaType = 'LocalBusiness';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /**
     * Ілюстрація хіро головної (шлях від кореня public, напр. /themes/<key>/hero.webp).
     * Векторна бренд-графіка живе в репо; фото-контент піде через Vich окремо.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $heroImagePath = null;

    // — Сторінка «Про нас» (спільна на всі міста бренду) —

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aboutTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aboutTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutRu = null;

    // - Юридичні сторінки (спільні на всі міста бренду) -

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privacyUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privacyRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $termsUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $termsRu = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBrandNameUk(): ?string
    {
        return $this->brandNameUk;
    }

    public function setBrandNameUk(?string $brandNameUk): self
    {
        $this->brandNameUk = null !== $brandNameUk && '' !== trim($brandNameUk) ? trim($brandNameUk) : null;

        return $this;
    }

    public function getBrandNameRu(): ?string
    {
        return $this->brandNameRu;
    }

    public function setBrandNameRu(?string $brandNameRu): self
    {
        $this->brandNameRu = null !== $brandNameRu && '' !== trim($brandNameRu) ? trim($brandNameRu) : null;

        return $this;
    }

    /** Коротка назва бренду з адмінки мовою сторінки (ru → uk) або null — тоді вирішує site_brand(). */
    public function brandName(string $locale): ?string
    {
        return 'ru' === $locale ? ($this->brandNameRu ?? $this->brandNameUk) : $this->brandNameUk;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getApexDomain(): string
    {
        return $this->apexDomain;
    }

    public function setApexDomain(string $apexDomain): self
    {
        $this->apexDomain = $apexDomain;

        return $this;
    }

    public function getSchemaType(): string
    {
        return $this->schemaType;
    }

    public function setSchemaType(string $schemaType): self
    {
        $this->schemaType = $schemaType;

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

    public function getHeroImagePath(): ?string
    {
        return $this->heroImagePath;
    }

    public function setHeroImagePath(?string $heroImagePath): self
    {
        $this->heroImagePath = $heroImagePath;

        return $this;
    }

    public function getAboutTitleUk(): ?string
    {
        return $this->aboutTitleUk;
    }

    public function setAboutTitleUk(?string $aboutTitleUk): self
    {
        $this->aboutTitleUk = $aboutTitleUk;

        return $this;
    }

    public function getAboutTitleRu(): ?string
    {
        return $this->aboutTitleRu;
    }

    public function setAboutTitleRu(?string $aboutTitleRu): self
    {
        $this->aboutTitleRu = $aboutTitleRu;

        return $this;
    }

    public function getAboutUk(): ?string
    {
        return $this->aboutUk;
    }

    public function setAboutUk(?string $aboutUk): self
    {
        $this->aboutUk = $aboutUk;

        return $this;
    }

    public function getAboutRu(): ?string
    {
        return $this->aboutRu;
    }

    public function setAboutRu(?string $aboutRu): self
    {
        $this->aboutRu = $aboutRu;

        return $this;
    }

    public function getPrivacyUk(): ?string
    {
        return $this->privacyUk;
    }

    public function setPrivacyUk(?string $privacyUk): self
    {
        $this->privacyUk = $privacyUk;

        return $this;
    }

    public function getPrivacyRu(): ?string
    {
        return $this->privacyRu;
    }

    public function setPrivacyRu(?string $privacyRu): self
    {
        $this->privacyRu = $privacyRu;

        return $this;
    }

    public function getTermsUk(): ?string
    {
        return $this->termsUk;
    }

    public function setTermsUk(?string $termsUk): self
    {
        $this->termsUk = $termsUk;

        return $this;
    }

    public function getTermsRu(): ?string
    {
        return $this->termsRu;
    }

    public function setTermsRu(?string $termsRu): self
    {
        $this->termsRu = $termsRu;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
