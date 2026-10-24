<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServicePostTagRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Тег статей бренду (24.10.2026, контентний сайт): /<блог>/tag/<slug>/. Новинні бренди — люди, чию пряму мову наведено
 * в статті (теги шле content-studio). Незалежний від рубрики: у статті одна рубрика й до 3 тегів. Мовні поля uk/ru/en;
 * тег існує мовою, коли є слаг і назва цією мовою (як ServicePostCategory::existsIn).
 */
#[ORM\Entity(repositoryClass: ServicePostTagRepository::class)]
#[UniqueEntity(fields: ['site', 'slugUk'], message: 'Тег з таким слагом uk у бренді вже є.', errorPath: 'slugUk', ignoreNull: true)]
#[UniqueEntity(fields: ['site', 'slugRu'], message: 'Тег з таким слагом ru у бренді вже є.', errorPath: 'slugRu', ignoreNull: true)]
#[UniqueEntity(fields: ['site', 'slugEn'], message: 'Тег з таким слагом en у бренді вже є.', errorPath: 'slugEn', ignoreNull: true)]
#[ORM\Table(name: 'service_post_tag')]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_tag_site_slug_uk', columns: ['site_id', 'slug_uk'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_tag_site_slug_ru', columns: ['site_id', 'slug_ru'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_tag_site_slug_en', columns: ['site_id', 'slug_en'])]
class ServicePostTag implements TimestampableInterface
{
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    /** Сторінка тегу з меншою кількістю статей мовою сторінки — noindex, follow і поза sitemap */
    public const INDEX_MIN_POSTS = 3;

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

    /** Тег існує мовою: є слаг і назва */
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /** Адмінка: назва й бренд — однойменні теги різних брендів у підказці поля «Теги» розрізняються */
    public function __toString(): string
    {
        $title = $this->titleUk ?? $this->titleEn ?? $this->titleRu ?? '';

        return $this->site !== null ? $title . ' · ' . $this->site->getSlug() : $title;
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
}
