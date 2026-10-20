<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceTeamMemberRepository;
use App\ServiceSite\ExtraFieldsTrait;
use App\ServiceSite\LocalizedFieldsTrait;
use App\Validator\UniqueTeamSlug;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Майстер / лікар команди (блок «Наша команда»). Скоупиться по місту.
 * Окрема сутність (не JSON у ServiceCity), бо фото — через Vich.
 *
 * Сторінки команди (10.2026, вмикає пак параметром app.team_prefix): /<префікс>/ — список міста, /<префікс>/<слаг>/ —
 * сторінка людини (лише зі слагом). city — основне місто (філія): там сторінка канонічна; cities — інші міста, де людина
 * теж працює (блок і список там її показують, canonical її сторінки веде на основне). items — послуги, які вона робить:
 * блок «Фахівці» на сторінці послуги й «Послуги» на сторінці людини. Додаткові поля (стаж, освіта…) — app.custom_fields.team.
 */
#[ORM\Entity(repositoryClass: ServiceTeamMemberRepository::class)]
#[ORM\Table(name: 'service_team_member')]
#[ORM\Index(columns: ['city_id', 'active', 'position'], name: 'idx_svc_team_city_active')]
#[Vich\Uploadable]
#[UniqueTeamSlug]
class ServiceTeamMember implements TimestampableInterface
{
    use ExtraFieldsTrait;
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Основне місто (філія): тут сторінка людини канонічна */
    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCity $city = null;

    /**
     * Інші міста сайту, де людина теж працює (лікар приймає у двох філіях). Порожньо = лише основне місто.
     *
     * @var Collection<int, ServiceCity>
     */
    #[ORM\ManyToMany(targetEntity: ServiceCity::class)]
    #[ORM\JoinTable(name: 'service_team_member_city')]
    private Collection $cities;

    /**
     * Послуги, які робить людина: блок «Фахівці» на сторінці послуги, «Послуги» на сторінці людини.
     *
     * @var Collection<int, ServiceItem>
     */
    #[ORM\ManyToMany(targetEntity: ServiceItem::class)]
    #[ORM\JoinTable(name: 'service_team_member_item')]
    private Collection $items;

    #[ORM\Column(length: 128)]
    private string $nameUk = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $nameRu = null;

    /** Роль/спеціалізація («Електромонтажник, стаж 12 років») */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $roleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $roleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descRu = null;

    /** Слаг сторінки /<app.team_prefix>/<слаг>/; порожньо = людина лише в блоках і списку, без своєї сторінки */
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Слаг — лише латиниця в нижньому регістрі, цифри й дефіс.')]
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $slugUk = null;

    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Слаг — лише латиниця в нижньому регістрі, цифри й дефіс.')]
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $slugRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescRu = null;

    #[Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 8000, maxHeight: 8000, mimeTypesMessage: 'Дозволено лише JPG/PNG/WebP.')]
    #[Vich\UploadableField(mapping: 'service_team_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAlt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    public function __construct()
    {
        $this->cities = new ArrayCollection();
        $this->items = new ArrayCollection();
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

    /** @return Collection<int, ServiceCity> */
    public function getCities(): Collection
    {
        return $this->cities;
    }

    public function addCity(ServiceCity $city): self
    {
        if (!$this->cities->contains($city)) {
            $this->cities->add($city);
        }

        return $this;
    }

    public function removeCity(ServiceCity $city): self
    {
        $this->cities->removeElement($city);

        return $this;
    }

    /** Чи працює людина в місті: основне або одне з інших */
    public function worksIn(ServiceCity $city): bool
    {
        return $this->city === $city || $this->cities->contains($city);
    }

    /**
     * Усі міста людини: основне першим, далі інші (без повторів).
     *
     * @return list<ServiceCity>
     */
    public function allCities(): array
    {
        $out = $this->city !== null ? [$this->city] : [];
        foreach ($this->cities as $c) {
            if (!\in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /** @return Collection<int, ServiceItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ServiceItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }

        return $this;
    }

    public function removeItem(ServiceItem $item): self
    {
        $this->items->removeElement($item);

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

    public function getRoleUk(): ?string
    {
        return $this->roleUk;
    }

    public function setRoleUk(?string $roleUk): self
    {
        $this->roleUk = $roleUk;

        return $this;
    }

    public function getRoleRu(): ?string
    {
        return $this->roleRu;
    }

    public function setRoleRu(?string $roleRu): self
    {
        $this->roleRu = $roleRu;

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

    public function getSlugUk(): ?string
    {
        return $this->slugUk;
    }

    public function setSlugUk(?string $slugUk): self
    {
        $this->slugUk = self::cleanSlug($slugUk);

        return $this;
    }

    public function getSlugRu(): ?string
    {
        return $this->slugRu;
    }

    public function setSlugRu(?string $slugRu): self
    {
        $this->slugRu = self::cleanSlug($slugRu);

        return $this;
    }

    /** Слаг за локаллю з фолбеком ru→uk; null — своєї сторінки нема */
    public function slug(string $locale): ?string
    {
        $slug = $this->loc('slug', $locale);

        return $slug === null || $slug === '' ? null : (string) $slug;
    }

    /** Чи є в людини своя сторінка (заповнено слаг uk) */
    public function hasPage(): bool
    {
        return $this->slugUk !== null;
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
        return $this->nameUk;
    }

    /** Порожній слаг з адмінки = null (людина без сторінки), а не '' (інакше два порожні слаги «конфліктують») */
    private static function cleanSlug(?string $slug): ?string
    {
        $slug = $slug !== null ? trim($slug) : null;

        return $slug === null || $slug === '' ? null : $slug;
    }
}
