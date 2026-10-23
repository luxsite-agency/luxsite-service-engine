<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Зображення галереї сторінки (ServicePage): скани ліцензій і сертифікатів, фото. Під сторінкою сіткою з підписами,
 * клік — оригінал. Редагується у формі сторінки (вкладка «Галерея»).
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_page_image')]
#[ORM\Index(columns: ['page_id', 'position'], name: 'idx_svc_page_image_page')]
#[Vich\Uploadable]
class ServicePageImage implements TimestampableInterface
{
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServicePage::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServicePage $page = null;

    #[Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 8000, maxHeight: 8000, mimeTypesMessage: 'Дозволено лише JPG/PNG/WebP.')]
    #[Vich\UploadableField(mapping: 'service_page_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    /** Підпис під зображенням («Ліцензія МОЗ на медичну практику»); він же alt */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $captionUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $captionRu = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    /** Рядок галереї без файлу нічого не показує — не зберігаємо */
    #[Assert\Callback]
    public function validateFile(ExecutionContextInterface $context): void
    {
        if ($this->imageFile === null && ($this->imageName === null || $this->imageName === '')) {
            $context->buildViolation('Додайте файл зображення або видаліть порожній рядок галереї.')->atPath('imageFile')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPage(): ?ServicePage
    {
        return $this->page;
    }

    public function setPage(?ServicePage $page): self
    {
        $this->page = $page;

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

    public function getCaptionUk(): ?string
    {
        return $this->captionUk;
    }

    public function setCaptionUk(?string $captionUk): self
    {
        $this->captionUk = $captionUk;

        return $this;
    }

    public function getCaptionRu(): ?string
    {
        return $this->captionRu;
    }

    public function setCaptionRu(?string $captionRu): self
    {
        $this->captionRu = $captionRu;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): self
    {
        $this->position = (int) $position;

        return $this;
    }

    public function __toString(): string
    {
        return (string) ($this->captionUk ?? $this->imageName ?? '');
    }
}
