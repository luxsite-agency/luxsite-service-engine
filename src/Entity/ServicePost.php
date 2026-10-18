<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServicePostRepository;
use App\ServiceSite\ExtraFieldsTrait;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Пост блогу сервісного сайту (/blog/<slug>/). Спільний на всі міста бренду.
 */
#[ORM\Entity(repositoryClass: ServicePostRepository::class)]
#[ORM\Table(name: 'service_post')]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_site_slug_uk', columns: ['site_id', 'slug_uk'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_site_slug_ru', columns: ['site_id', 'slug_ru'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_site_studio_group', columns: ['site_id', 'studio_group_id'])]
#[ORM\Index(columns: ['site_id', 'active', 'published_at'], name: 'idx_svc_post_site_published')]
#[Vich\Uploadable]
class ServicePost implements TimestampableInterface
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

    /** id статті iblog у Joomla-джерелі (ідемпотентний імпорт) */
    #[ORM\Column(nullable: true)]
    private ?int $legacyId = null;

    /** groupId статті в content-studio (satellit): спільний для uk/ru, ключ upsert приймача /api/studio */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $studioGroupId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $studioSyncedAt = null;

    #[ORM\Column(length: 160)]
    private string $slugUk = '';

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $slugRu = null;

    #[ORM\Column(length: 255)]
    private string $titleUk = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleRu = null;

    /** Лід-анонс (стрічка + meta description) */
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 8000, maxHeight: 8000, mimeTypesMessage: 'Дозволено лише JPG/PNG/WebP.')]
    #[Vich\UploadableField(mapping: 'service_post_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAlt = null;

    /** alt обкладинки для ru (null = фолбек на imageAlt); панель content-studio пише по мовах */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAltRu = null;

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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

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

    public function getStudioGroupId(): ?string
    {
        return $this->studioGroupId;
    }

    public function setStudioGroupId(?string $studioGroupId): self
    {
        $this->studioGroupId = $studioGroupId;

        return $this;
    }

    public function getStudioSyncedAt(): ?\DateTimeImmutable
    {
        return $this->studioSyncedAt;
    }

    public function setStudioSyncedAt(?\DateTimeImmutable $studioSyncedAt): self
    {
        $this->studioSyncedAt = $studioSyncedAt;

        return $this;
    }

    public function getImageAltRu(): ?string
    {
        return $this->imageAltRu;
    }

    public function setImageAltRu(?string $imageAltRu): self
    {
        $this->imageAltRu = $imageAltRu;

        return $this;
    }

    /** alt за локаллю з фолбеком ru→uk (у шаблонах: post.imageAlt(locale)) */
    public function imageAlt(string $locale): ?string
    {
        return $locale === 'ru' && $this->imageAltRu !== null && $this->imageAltRu !== '' ? $this->imageAltRu : $this->imageAlt;
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
}
