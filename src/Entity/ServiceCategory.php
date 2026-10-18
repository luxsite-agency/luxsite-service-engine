<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceCategoryRepository;
use App\ServiceSite\ExtraFieldsTrait;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Категорія послуг сервісного сайту. Спільна на всі міста бренду.
 * Слаги per-мова (URL: /services/<slugUk>/ і /ru/services/<slugRu>/).
 */
#[ORM\Entity(repositoryClass: ServiceCategoryRepository::class)]
#[ORM\Table(name: 'service_category')]
#[ORM\UniqueConstraint(name: 'uniq_svc_cat_site_slug_uk', columns: ['site_id', 'slug_uk'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_cat_site_slug_ru', columns: ['site_id', 'slug_ru'])]
#[Vich\Uploadable]
class ServiceCategory implements TimestampableInterface
{
    use ExtraFieldsTrait;
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceSite $site = null;

    /** id категорії в Joomla-джерелі (ідемпотентний імпорт) */
    #[ORM\Column(nullable: true)]
    private ?int $legacyId = null;

    #[ORM\Column(length: 64)]
    private string $slugUk = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $slugRu = null;

    #[ORM\Column(length: 255)]
    private string $titleUk = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleRu = null;

    /** Лід (вступ під H1), простий текст або короткий HTML; порожньо = нема */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadRu = null;

    /**
     * FAQ сторінки категорії: [{q, a}, ...] (як у послуги)
     *
     * @var list<array{q: string, a: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqUk = null;

    /** @var list<array{q: string, a: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqRu = null;

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

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescRu = null;

    /** Порядок у списках/меню */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /**
     * Посадкова сторінка категорії (nested): true — повна сторінка (хіро, лід, сітка, SEO-блоки), у sitemap, крихта-посилання;
     * false — сторінка лишається (URL послуг /services/<кат>/<послуга>/ не ламаються), але noindex,follow, без sitemap,
     * без посилань на неї з каталогу й крихт. Рішення власника 11.10.2026.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $landing = true;

    #[Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 8000, maxHeight: 8000, mimeTypesMessage: 'Дозволено лише JPG/PNG/WebP.')]
    #[Vich\UploadableField(mapping: 'service_category_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAlt = null;

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

    public function getTitleUk(): string
    {
        return $this->titleUk;
    }

    public function setTitleUk(string $titleUk): self
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

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function setImageFile(?File $imageFile): self
    {
        $this->imageFile = $imageFile;
        if ($imageFile !== null) {
            // інакше Doctrine не бачить змін і Vich не збереже файл
            $this->setUpdatedAt(new \DateTime());
        }

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

    /** Слаг за локаллю з фолбеком ru→uk */
    public function slug(string $locale): string
    {
        return (string) $this->loc('slug', $locale);
    }

    public function __toString(): string
    {
        return $this->titleUk;
    }

    public function isLanding(): bool
    {
        return $this->landing;
    }

    public function setLanding(bool $landing): self
    {
        $this->landing = $landing;

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

    /** @return list<array{q: string, a: string}>|null */
    public function getFaqUk(): ?array
    {
        return $this->faqUk;
    }

    /** @param list<array{q: string, a: string}>|null $faqUk */
    public function setFaqUk(?array $faqUk): self
    {
        $this->faqUk = $faqUk;

        return $this;
    }

    /** @return list<array{q: string, a: string}>|null */
    public function getFaqRu(): ?array
    {
        return $this->faqRu;
    }

    /** @param list<array{q: string, a: string}>|null $faqRu */
    public function setFaqRu(?array $faqRu): self
    {
        $this->faqRu = $faqRu;

        return $this;
    }
}
