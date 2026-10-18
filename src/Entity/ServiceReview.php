<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceReviewRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Відгук клієнта (блок на головній/сторінках послуг + AggregateRating).
 * Скоупиться по місту. Поки що вносяться в адмінці; UGC-форма — окремим етапом.
 */
#[ORM\Entity(repositoryClass: ServiceReviewRepository::class)]
#[ORM\Table(name: 'service_review')]
#[ORM\Index(columns: ['city_id', 'active'], name: 'idx_svc_review_city_active')]
#[ORM\UniqueConstraint(name: 'uniq_svc_review_user_city', columns: ['user_id', 'city_id'])]
#[Vich\Uploadable]
class ServiceReview implements TimestampableInterface
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

    /** id запису в старій CMS (ідемпотентний імпорт app:joomla:import); null = створено в рушії */
    #[ORM\Column(nullable: true)]
    private ?int $legacyId = null;

    /** Google-юзер, який подав відгук; null = імпорт/ручне внесення в адмінці */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Аватар з Google-профілю (https-URL); пріоритет має Vich-фото imageName */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $avatarUrl = null;

    /** Ім'я автора відгуку */
    #[ORM\Column(length: 128)]
    private string $authorUk = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $authorRu = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $textUk = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $textRu = null;

    /** Звідки автор («Київ», «Львівська обл.») — підпис під іменем; порожньо = нема */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 5])]
    #[Assert\Range(min: 1, max: 5)]
    private int $stars = 5;

    /** Дата відгуку (показується на сайті і йде в JSON-LD Review) */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 8000, maxHeight: 8000, mimeTypesMessage: 'Дозволено лише JPG/PNG/WebP.')]
    #[Vich\UploadableField(mapping: 'service_review_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAlt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    public function getLegacyId(): ?int
    {
        return $this->legacyId;
    }

    public function setLegacyId(?int $legacyId): self
    {
        $this->legacyId = $legacyId;

        return $this;
    }

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): self
    {
        $this->avatarUrl = $avatarUrl;

        return $this;
    }

    public function getAuthorUk(): string
    {
        return $this->authorUk;
    }

    public function setAuthorUk(string $authorUk): self
    {
        $this->authorUk = $authorUk;

        return $this;
    }

    public function getAuthorRu(): ?string
    {
        return $this->authorRu;
    }

    public function setAuthorRu(?string $authorRu): self
    {
        $this->authorRu = $authorRu;

        return $this;
    }

    public function getTextUk(): string
    {
        return $this->textUk;
    }

    public function setTextUk(string $textUk): self
    {
        $this->textUk = $textUk;

        return $this;
    }

    public function getTextRu(): ?string
    {
        return $this->textRu;
    }

    public function setTextRu(?string $textRu): self
    {
        $this->textRu = $textRu;

        return $this;
    }

    public function getStars(): int
    {
        return $this->stars;
    }

    public function setStars(int $stars): self
    {
        $this->stars = $stars;

        return $this;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): self
    {
        $this->reviewedAt = $reviewedAt;

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
        return $this->authorUk . ' (' . $this->stars . '★)';
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;

        return $this;
    }
}
