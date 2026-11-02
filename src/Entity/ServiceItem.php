<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceItemRepository;
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
 * Послуга (сторінка каталогу). Спільна на всі міста бренду;
 * per-місто відрізняються лише ціни (ServicePrice).
 * URL: /services/<категорія>/<slugUk>/ та /ru/services/<категорія-ru>/<slugRu>/.
 */
#[ORM\Entity(repositoryClass: ServiceItemRepository::class)]
#[ORM\Table(name: 'service_item')]
#[ORM\UniqueConstraint(name: 'uniq_svc_item_site_slug_uk', columns: ['site_id', 'slug_uk'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_item_site_slug_ru', columns: ['site_id', 'slug_ru'])]
#[ORM\Index(columns: ['category_id', 'active', 'position'], name: 'idx_svc_item_cat_active')]
#[ORM\Index(columns: ['calc_type_id'], name: 'idx_item_calc_type')]
#[Vich\Uploadable]
class ServiceItem implements TimestampableInterface
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

    #[ORM\ManyToOne(targetEntity: ServiceCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCategory $category = null;

    /**
     * Міста, де послуга НЕ надається (рішення власника 14.10.2026: «в одному місті робиться, в іншому ні»): там її нема
     * в каталозі, категорії, на головній, у районах, футері, sitemap, «інших послугах» і калькуляторі, а сторінка
     * послуги на хості такого міста веде 301 на місто, де вона є (апекс першим), або 404. Порожньо = всюди.
     *
     * @var Collection<int, ServiceCity>
     */
    #[ORM\ManyToMany(targetEntity: ServiceCity::class)]
    #[ORM\JoinTable(name: 'service_item_hidden_city')]
    private Collection $hiddenCities;

    /**
     * Міста, де послуга є, але ціни на неї НЕ публікуємо: без таблиці цін на сторінці, вкладки цін на головній,
     * Offer у JSON-LD і позицій у калькуляторі. Сам прайс (ServicePrice) лишається в адмінці. Порожньо = ціни всюди.
     *
     * @var Collection<int, ServiceCity>
     */
    #[ORM\ManyToMany(targetEntity: ServiceCity::class)]
    #[ORM\JoinTable(name: 'service_item_price_hidden_city')]
    private Collection $priceHiddenCities;

    /**
     * Тип прибирання для міні-калькулятора на сторінці послуги.
     * Порожньо - калькулятор на сторінці не показуємо (послуги, які
     * рахуються не за площею: хімчистка, вікна, вивіз сміття).
     */
    #[ORM\ManyToOne(targetEntity: ServiceCalcType::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServiceCalcType $calcType = null;

    /** id статті в Joomla-джерелі (ідемпотентний імпорт) */
    #[ORM\Column(nullable: true)]
    private ?int $legacyId = null;

    #[ORM\Column(length: 160)]
    private string $slugUk = '';

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $slugRu = null;

    #[ORM\Column(length: 255)]
    private string $titleUk = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleRu = null;

    /** Лід-абзац під H1 */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadRu = null;

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

    /** Підпис вкладки в таблицях цін */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tabTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tabTitleRu = null;

    /** Коротка назва для карток, меню й таблиць («Самоскиди» при H1 «Перевезення самоскидами»); порожньо = title */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nameUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nameRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescRu = null;

    /**
     * FAQ сторінки послуги: [{q, a}, ...]
     *
     * @var list<array{q: string, a: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqUk = null;

    /** @var list<array{q: string, a: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqRu = null;

    /** УТП-прапорець: true = блок «переваги», false = блок «довіра» (як catalog_utp) */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $utp = false;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 8000, maxHeight: 8000, mimeTypesMessage: 'Дозволено лише JPG/PNG/WebP.')]
    #[Vich\UploadableField(mapping: 'service_item_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAlt = null;

    /** Послуга і її категорія мусять належати одному бренду (тенант-ізоляція) */
    #[Assert\Callback]
    public function validateCategorySite(ExecutionContextInterface $context): void
    {
        if ($this->category !== null && $this->site !== null && $this->category->getSite() !== $this->site) {
            $context->buildViolation('Категорія належить іншому сайту-бренду, ніж послуга.')
                ->atPath('category')
                ->addViolation();
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

    public function getCategory(): ?ServiceCategory
    {
        return $this->category;
    }

    public function setCategory(?ServiceCategory $category): self
    {
        $this->category = $category;

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

    public function getTabTitleUk(): ?string
    {
        return $this->tabTitleUk;
    }

    public function setTabTitleUk(?string $tabTitleUk): self
    {
        $this->tabTitleUk = $tabTitleUk;

        return $this;
    }

    public function getTabTitleRu(): ?string
    {
        return $this->tabTitleRu;
    }

    public function setTabTitleRu(?string $tabTitleRu): self
    {
        $this->tabTitleRu = $tabTitleRu;

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

    public function isUtp(): bool
    {
        return $this->utp;
    }

    public function setUtp(bool $utp): self
    {
        $this->utp = $utp;

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

    public function getCalcType(): ?ServiceCalcType
    {
        return $this->calcType;
    }

    public function setCalcType(?ServiceCalcType $calcType): self
    {
        $this->calcType = $calcType;

        return $this;
    }

    public function __construct()
    {
        $this->hiddenCities = new ArrayCollection();
        $this->priceHiddenCities = new ArrayCollection();
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

    /** @return Collection<int, ServiceCity> */
    public function getPriceHiddenCities(): Collection
    {
        return $this->priceHiddenCities;
    }

    public function addPriceHiddenCity(ServiceCity $city): self
    {
        if (!$this->priceHiddenCities->contains($city)) {
            $this->priceHiddenCities->add($city);
        }

        return $this;
    }

    public function removePriceHiddenCity(ServiceCity $city): self
    {
        $this->priceHiddenCities->removeElement($city);

        return $this;
    }

    /** Чи надається послуга в місті (нема в «Не надається в містах») */
    public function isAvailableIn(ServiceCity $city): bool
    {
        return !$this->hiddenCities->contains($city);
    }

    /** Чи публікуємо ціни на послугу в місті (послуга є і місто не в «Ціни не показувати») */
    public function showsPricesIn(ServiceCity $city): bool
    {
        return $this->isAvailableIn($city) && !$this->priceHiddenCities->contains($city);
    }

    public function __toString(): string
    {
        return $this->titleUk;
    }

    public function getNameUk(): ?string
    {
        return $this->nameUk;
    }

    public function setNameUk(?string $nameUk): self
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

    /** Коротка назва в локалі; без неї — повний заголовок. */
    public function shortName(string $locale): string
    {
        return (string) ($this->loc('name', $locale) ?: $this->loc('title', $locale));
    }
}
