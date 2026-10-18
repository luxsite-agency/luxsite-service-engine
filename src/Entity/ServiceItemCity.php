<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceItemCityRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Тексти послуги під конкретне місто.
 *
 * Сама послуга лежить на рівні сайту - одна на всі міста. Поки міста були
 * закриті від індексації, це не заважало, але після запуску Дніпра, Одеси й
 * Харкова однакові сторінки на чотирьох хостах стали дублями. Тут - переписані
 * версії тих самих текстів; порожнє поле означає «беремо з послуги».
 */
#[ORM\Entity(repositoryClass: ServiceItemCityRepository::class)]
#[ORM\Table(name: 'service_item_city')]
#[ORM\UniqueConstraint(name: 'uniq_item_city', columns: ['item_id', 'city_id'])]
#[ORM\Index(columns: ['item_id'], name: 'IDX_SIC_ITEM')]
#[ORM\Index(columns: ['city_id'], name: 'IDX_SIC_CITY')]
class ServiceItemCity implements TimestampableInterface
{
    use LocalizedFieldsTrait;
    // потрібні для lastmod у карті сайту: тексти під місто живуть саме тут
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceItem::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceItem $item = null;

    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCity $city = null;

    /* Перевизначення під місто (10.2026, сайт із двома містами на різних хостах): назва (H1, крихти, JSON-LD), SEO-мета, другий/третій
       блоки тексту, назва вкладки; порожнє = з послуги. Меню й списки беруть назву з послуги. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $desc2Uk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $desc2Ru = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $desc3Uk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $desc3Ru = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tabTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tabTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descRu = null;

    /** @var array<int, array{q: string, a: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqUk = null;

    /** @var array<int, array{q: string, a: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqRu = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCity(): ?ServiceCity
    {
        return $this->city;
    }

    public function setCity(?ServiceCity $city): self
    {
        $this->city = $city;

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

    /** @return array<int, array{q: string, a: string}>|null */
    public function getFaqUk(): ?array
    {
        return $this->faqUk;
    }

    public function setFaqUk(?array $faqUk): self
    {
        $this->faqUk = $faqUk;

        return $this;
    }

    /** @return array<int, array{q: string, a: string}>|null */
    public function getFaqRu(): ?array
    {
        return $this->faqRu;
    }

    public function setFaqRu(?array $faqRu): self
    {
        $this->faqRu = $faqRu;

        return $this;
    }

    public function __toString(): string
    {
        return ($this->item?->getTitleUk() ?? '?') . ' / ' . ($this->city?->name('uk') ?? '?');
    }

    public function getTitleUk(): ?string
    {
        return $this->titleUk;
    }

    public function setTitleUk(?string $titleUk): self
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
}
