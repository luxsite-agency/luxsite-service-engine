<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServicePostCategoryRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use App\Validator\RootCategorySlug;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Рубрика блогу (22.10.2026, контентний сайт): /<блог>/category/<slug>/. Мовні поля uk/ru/en; рубрика існує мовою,
 * коли є слаг і назва цією мовою (як ServicePost::existsIn).
 */
#[ORM\Entity(repositoryClass: ServicePostCategoryRepository::class)]
#[RootCategorySlug]
#[ORM\Table(name: 'service_post_category')]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_cat_site_slug_uk', columns: ['site_id', 'slug_uk'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_cat_site_slug_ru', columns: ['site_id', 'slug_ru'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_cat_site_slug_en', columns: ['site_id', 'slug_en'])]
class ServicePostCategory implements TimestampableInterface
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

    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Regex('/^[a-z0-9-]+$/', message: 'Слаг: лише латиниця, цифри, дефіс.')]
    private ?string $slugUk = null;

    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Regex('/^[a-z0-9-]+$/', message: 'Слаг: лише латиниця, цифри, дефіс.')]
    private ?string $slugRu = null;

    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Regex('/^[a-z0-9-]+$/', message: 'Слаг: лише латиниця, цифри, дефіс.')]
    private ?string $slugEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleEn = null;

    /** Вступ над списком статей рубрики (HTML) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescEn = null;

    #[ORM\Column(options: ['default' => 0])]
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

    /** Слаг мовою (ru — з фолбеком на uk, en — без) */
    public function slug(string $locale): string
    {
        return (string) $this->loc('slug', $locale);
    }

    /** Рубрика існує мовою: є слаг і назва */
    public function existsIn(string $locale): bool
    {
        return trim($this->slug($locale)) !== '' && trim((string) $this->loc('title', $locale)) !== '';
    }

    #[Assert\Callback]
    public function validateHasLanguage(ExecutionContextInterface $context): void
    {
        foreach (['uk', 'en'] as $l) {
            if ($this->existsIn($l)) {
                return;
            }
        }
        $context->buildViolation('Потрібні назва й слаг хоча б однією мовою.')->atPath('titleUk')->addViolation();
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
        return $this->titleUk ?? $this->titleEn ?? $this->titleRu ?? '';
    }

    public function getSlugUk(): ?string
    {
        return $this->slugUk;
    }

    public function setSlugUk(?string $slugUk): self
    {
        $this->slugUk = $slugUk === '' ? null : $slugUk;

        return $this;
    }

    public function getSlugRu(): ?string
    {
        return $this->slugRu;
    }

    public function setSlugRu(?string $slugRu): self
    {
        $this->slugRu = $slugRu === '' ? null : $slugRu;

        return $this;
    }

    public function getSlugEn(): ?string
    {
        return $this->slugEn;
    }

    public function setSlugEn(?string $slugEn): self
    {
        $this->slugEn = $slugEn === '' ? null : $slugEn;

        return $this;
    }

    public function getTitleUk(): ?string
    {
        return $this->titleUk;
    }

    public function setTitleUk(?string $titleUk): self
    {
        $this->titleUk = $titleUk === '' ? null : $titleUk;

        return $this;
    }

    public function getTitleRu(): ?string
    {
        return $this->titleRu;
    }

    public function setTitleRu(?string $titleRu): self
    {
        $this->titleRu = $titleRu === '' ? null : $titleRu;

        return $this;
    }

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(?string $titleEn): self
    {
        $this->titleEn = $titleEn === '' ? null : $titleEn;

        return $this;
    }

    public function getDescUk(): ?string
    {
        return $this->descUk;
    }

    public function setDescUk(?string $descUk): self
    {
        $this->descUk = $descUk === '' ? null : $descUk;

        return $this;
    }

    public function getDescRu(): ?string
    {
        return $this->descRu;
    }

    public function setDescRu(?string $descRu): self
    {
        $this->descRu = $descRu === '' ? null : $descRu;

        return $this;
    }

    public function getDescEn(): ?string
    {
        return $this->descEn;
    }

    public function setDescEn(?string $descEn): self
    {
        $this->descEn = $descEn === '' ? null : $descEn;

        return $this;
    }

    public function getMetaTitleUk(): ?string
    {
        return $this->metaTitleUk;
    }

    public function setMetaTitleUk(?string $metaTitleUk): self
    {
        $this->metaTitleUk = $metaTitleUk === '' ? null : $metaTitleUk;

        return $this;
    }

    public function getMetaTitleRu(): ?string
    {
        return $this->metaTitleRu;
    }

    public function setMetaTitleRu(?string $metaTitleRu): self
    {
        $this->metaTitleRu = $metaTitleRu === '' ? null : $metaTitleRu;

        return $this;
    }

    public function getMetaTitleEn(): ?string
    {
        return $this->metaTitleEn;
    }

    public function setMetaTitleEn(?string $metaTitleEn): self
    {
        $this->metaTitleEn = $metaTitleEn === '' ? null : $metaTitleEn;

        return $this;
    }

    public function getMetaDescUk(): ?string
    {
        return $this->metaDescUk;
    }

    public function setMetaDescUk(?string $metaDescUk): self
    {
        $this->metaDescUk = $metaDescUk === '' ? null : $metaDescUk;

        return $this;
    }

    public function getMetaDescRu(): ?string
    {
        return $this->metaDescRu;
    }

    public function setMetaDescRu(?string $metaDescRu): self
    {
        $this->metaDescRu = $metaDescRu === '' ? null : $metaDescRu;

        return $this;
    }

    public function getMetaDescEn(): ?string
    {
        return $this->metaDescEn;
    }

    public function setMetaDescEn(?string $metaDescEn): self
    {
        $this->metaDescEn = $metaDescEn === '' ? null : $metaDescEn;

        return $this;
    }
}
