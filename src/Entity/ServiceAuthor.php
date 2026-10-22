<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceAuthorRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Автор статей бренду (22.10.2026, контентний сайт): /author/<slug>/, JSON-LD Person. Ім'я, біо й фото задає пак
 * або адмінка — рушій персон не вигадує (рішення координатора 22.10.2026).
 */
#[ORM\Entity(repositoryClass: ServiceAuthorRepository::class)]
#[ORM\Table(name: 'service_author')]
#[ORM\UniqueConstraint(name: 'uniq_svc_author_site_slug', columns: ['site_id', 'slug'])]
#[Vich\Uploadable]
class ServiceAuthor implements TimestampableInterface
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

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9-]+$/', message: 'Слаг: лише латиниця, цифри, дефіс.')]
    private string $slug = '';

    /** Ім'я, як його пишуть на сайті (одне на всі мови) */
    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $name = '';

    /** Посада/роль під іменем */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $roleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $roleRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $roleEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bioUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bioRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bioEn = null;

    #[Assert\Image(maxSize: '5M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 4000, maxHeight: 4000, mimeTypesMessage: 'Дозволено лише JPG/PNG/WebP.')]
    #[Vich\UploadableField(mapping: 'service_author_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = (string) $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = (string) $name;

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
            $this->updatedAt = new \DateTime();
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
        return $this->name;
    }

    public function getRoleUk(): ?string
    {
        return $this->roleUk;
    }

    public function setRoleUk(?string $roleUk): self
    {
        $this->roleUk = $roleUk === '' ? null : $roleUk;

        return $this;
    }

    public function getRoleRu(): ?string
    {
        return $this->roleRu;
    }

    public function setRoleRu(?string $roleRu): self
    {
        $this->roleRu = $roleRu === '' ? null : $roleRu;

        return $this;
    }

    public function getRoleEn(): ?string
    {
        return $this->roleEn;
    }

    public function setRoleEn(?string $roleEn): self
    {
        $this->roleEn = $roleEn === '' ? null : $roleEn;

        return $this;
    }

    public function getBioUk(): ?string
    {
        return $this->bioUk;
    }

    public function setBioUk(?string $bioUk): self
    {
        $this->bioUk = $bioUk === '' ? null : $bioUk;

        return $this;
    }

    public function getBioRu(): ?string
    {
        return $this->bioRu;
    }

    public function setBioRu(?string $bioRu): self
    {
        $this->bioRu = $bioRu === '' ? null : $bioRu;

        return $this;
    }

    public function getBioEn(): ?string
    {
        return $this->bioEn;
    }

    public function setBioEn(?string $bioEn): self
    {
        $this->bioEn = $bioEn === '' ? null : $bioEn;

        return $this;
    }
}
