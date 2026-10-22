<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServicePageRepository;
use App\ServiceSite\ExtraFieldsTrait;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Довільна сторінка сайту (app.pages): вакансії, ліцензії, будь-який текст поза каталогом. Адреса — від кореня:
 * /<slug>/ або /<батько>/<slug>/ (один рівень вкладеності), ru — /ru/…, без префікса на кшталт /page/.
 * Спільна на всі міста бренду; «Не показувати в містах» — як у послуги, лише без 301 (там сторінка 404).
 * Слаги унікальні в межах сайту (як у послуг); збіг зі слагом категорії/послуги в корені й фіксованими розділами
 * ловлять адмінка й app:site:slugs (App\ServiceSite\RootSlugs). Маршрут — App\Controller\Services\ServicePageController.
 */
#[ORM\Entity(repositoryClass: ServicePageRepository::class)]
#[ORM\Table(name: 'service_page')]
#[ORM\UniqueConstraint(name: 'uniq_svc_page_site_slug_uk', columns: ['site_id', 'slug_uk'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_page_site_slug_ru', columns: ['site_id', 'slug_ru'])]
#[ORM\Index(columns: ['site_id', 'active', 'position'], name: 'idx_svc_page_site_active')]
#[Vich\Uploadable]
class ServicePage implements TimestampableInterface
{
    use ExtraFieldsTrait;
    use LocalizedFieldsTrait;
    use TimestampsTrait;

    /** Слаг — сегмент адреси, як у маршруті (config/routes, sv_page) */
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceSite $site = null;

    /**
     * Батьківська сторінка (/<батько>/<slug>/): лише сторінка верхнього рівня. Батька з дочірніми адмінка не видаляє
     * (інакше їхні адреси переїхали б у корінь). SET NULL у БД — для каскаду з сайту: InnoDB перевіряє RESTRICT
     * порядково, і видалення сайту зі сторінками падало б на порядку рядків.
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServicePage $parent = null;

    /** @var Collection<int, ServicePage> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $children;

    /** @var Collection<int, ServicePageImage> галерея (скани ліцензій, фото) */
    #[ORM\OneToMany(targetEntity: ServicePageImage::class, mappedBy: 'page', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $images;

    /**
     * Міста, де сторінки НЕ показуємо (на хості такого міста — 404, у sitemap і футері її нема). Порожньо = всюди.
     *
     * @var Collection<int, ServiceCity>
     */
    #[ORM\ManyToMany(targetEntity: ServiceCity::class)]
    #[ORM\JoinTable(name: 'service_page_hidden_city')]
    private Collection $hiddenCities;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Слаг uk обовʼязковий.')]
    #[Assert\Regex(pattern: self::SLUG_PATTERN, message: 'Слаг — лише латиниця в нижньому регістрі, цифри й дефіс (vakansii).')]
    private string $slugUk = '';

    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Regex(pattern: self::SLUG_PATTERN, message: 'Слаг — лише латиниця в нижньому регістрі, цифри й дефіс (vakansii).')]
    private ?string $slugRu = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Заголовок uk обовʼязковий.')]
    private string $titleUk = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleRu = null;

    /** Вступ під H1 (простий текст), він же meta description, якщо своєї нема */
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

    /** Порядок серед сторінок одного батька і в футері */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    /** Посилання на сторінку в футері (поруч із «Про нас», «Контакти») */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $inFooter = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 8000, maxHeight: 8000, mimeTypesMessage: 'Дозволено лише JPG/PNG/WebP.')]
    #[Vich\UploadableField(mapping: 'service_page_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAlt = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->hiddenCities = new ArrayCollection();
    }

    /** Один рівень вкладеності, батько — з того ж сайту і не сама сторінка */
    #[Assert\Callback]
    public function validateParent(ExecutionContextInterface $context): void
    {
        $parent = $this->parent;
        if ($parent === null) {
            return;
        }
        if ($parent === $this || ($this->id !== null && $parent->getId() === $this->id)) {
            $context->buildViolation('Сторінка не може бути батьком сама собі.')->atPath('parent')->addViolation();

            return;
        }
        if ($this->site !== null && $parent->getSite() !== $this->site) {
            $context->buildViolation('Батьківська сторінка належить іншому сайту-бренду.')->atPath('parent')->addViolation();
        }
        if ($parent->getParent() !== null) {
            $context->buildViolation('Батьком може бути лише сторінка верхнього рівня (адреса /<батько>/<слаг>/, глибше — ні).')
                ->atPath('parent')->addViolation();
        }
        if (!$this->children->isEmpty()) {
            $context->buildViolation('У цієї сторінки є дочірні — вона може бути лише верхнього рівня.')->atPath('parent')->addViolation();
        }
    }

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

    public function getParent(): ?ServicePage
    {
        return $this->parent;
    }

    public function setParent(?ServicePage $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, ServicePage> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /** @return Collection<int, ServicePageImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(ServicePageImage $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setPage($this);
        }

        return $this;
    }

    public function removeImage(ServicePageImage $image): self
    {
        $this->images->removeElement($image);

        return $this;
    }

    /** @return Collection<int, ServiceCity> */
    public function getHiddenCities(): Collection
    {
        return $this->hiddenCities;
    }

    public function addHiddenCity(ServiceCity $city): self
    {
        if (!$this->hiddenCities->contains($city)) {
            $this->hiddenCities->add($city);
        }

        return $this;
    }

    public function removeHiddenCity(ServiceCity $city): self
    {
        $this->hiddenCities->removeElement($city);

        return $this;
    }

    /** Чи видно сторінку в місті: сама не прихована там, і батько (якщо є) теж */
    public function isAvailableIn(ServiceCity $city): bool
    {
        return !$this->hiddenCities->contains($city) && ($this->parent === null || $this->parent->isAvailableIn($city));
    }

    /** Активна сама і батько (дочірня сторінка вимкненого батька недосяжна) */
    public function isLive(): bool
    {
        return $this->active && ($this->parent === null || $this->parent->isActive());
    }

    public function getSlugUk(): string
    {
        return $this->slugUk;
    }

    public function setSlugUk(?string $slugUk): self
    {
        $this->slugUk = trim((string) $slugUk);

        return $this;
    }

    public function getSlugRu(): ?string
    {
        return $this->slugRu;
    }

    public function setSlugRu(?string $slugRu): self
    {
        $slugRu = $slugRu !== null ? trim($slugRu) : null;
        $this->slugRu = $slugRu === '' ? null : $slugRu;

        return $this;
    }

    public function getTitleUk(): string
    {
        return $this->titleUk;
    }

    public function setTitleUk(?string $titleUk): self
    {
        $this->titleUk = (string) $titleUk;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): self
    {
        $this->position = (int) $position;

        return $this;
    }

    public function isInFooter(): bool
    {
        return $this->inFooter;
    }

    public function setInFooter(bool $inFooter): self
    {
        $this->inFooter = $inFooter;

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

    /** Шлях без слешів по краях для маршруту sv_page: «vakansii» або «vakansii/likar» */
    public function path(string $locale): string
    {
        return $this->parent !== null ? $this->parent->slug($locale) . '/' . $this->slug($locale) : $this->slug($locale);
    }

    public function __toString(): string
    {
        return $this->parent !== null ? $this->parent->getTitleUk() . ' → ' . $this->titleUk : $this->titleUk;
    }
}
