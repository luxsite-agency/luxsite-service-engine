<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceWorkRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Наші роботи: власні фото з об'єктів.
 *
 * Порожнє місто = робота показується на всіх чотирьох сайтах, порожня
 * послуга = на всіх сторінках послуг. Так один вдалий кадр не треба
 * заводити чотири рази.
 *
 * «До» - необовʼязкове: у наявних зйомках пар немає, усе знято в уже
 * чистих приміщеннях. Поле лишили на випадок справжньої пари, а не щоб
 * імітувати її кадруванням.
 */
#[ORM\Entity(repositoryClass: ServiceWorkRepository::class)]
#[ORM\Table(name: 'service_work')]
#[ORM\Index(columns: ['city_id', 'active', 'position'], name: 'idx_work_city')]
#[ORM\Index(columns: ['item_id', 'active', 'position'], name: 'idx_work_item')]
#[Vich\Uploadable]
class ServiceWork implements TimestampableInterface
{
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Порожньо - робота показується в усіх містах */
    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ServiceCity $city = null;

    /** Порожньо - робота показується на всіх сторінках послуг */
    #[ORM\ManyToOne(targetEntity: ServiceItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServiceItem $item = null;

    #[ORM\Column(length: 160)]
    private string $titleUk = '';

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $titleRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $captionUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $captionRu = null;

    #[Vich\UploadableField(mapping: 'service_work_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAlt = null;

    #[Vich\UploadableField(mapping: 'service_work_media', fileNameProperty: 'beforeName')]
    private ?File $beforeFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $beforeName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $beforeAlt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

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

    public function getItem(): ?ServiceItem
    {
        return $this->item;
    }

    public function setItem(?ServiceItem $item): self
    {
        $this->item = $item;

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

    public function getBeforeFile(): ?File
    {
        return $this->beforeFile;
    }

    public function setBeforeFile(?File $beforeFile): self
    {
        $this->beforeFile = $beforeFile;
        if ($beforeFile !== null) {
            $this->setUpdatedAt(new \DateTime());
        }

        return $this;
    }

    public function getBeforeName(): ?string
    {
        return $this->beforeName;
    }

    public function setBeforeName(?string $beforeName): self
    {
        $this->beforeName = $beforeName;

        return $this;
    }

    public function getBeforeAlt(): ?string
    {
        return $this->beforeAlt;
    }

    public function setBeforeAlt(?string $beforeAlt): self
    {
        $this->beforeAlt = $beforeAlt;

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

    /** Пара «до/після» показується лише тоді, коли є обидва кадри. */
    public function hasPair(): bool
    {
        return $this->imageName !== null && $this->beforeName !== null;
    }

    public function __toString(): string
    {
        return $this->titleUk !== '' ? $this->titleUk : 'Робота';
    }
}
