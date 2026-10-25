<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServicePostRepository;
use App\ServiceSite\ExtraFieldsTrait;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

/**
 * Пост блогу сервісного сайту (/blog/<slug>/). Спільний на всі міста бренду.
 */
#[ORM\Entity(repositoryClass: ServicePostRepository::class)]
#[ORM\Table(name: 'service_post')]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_site_slug_uk', columns: ['site_id', 'slug_uk'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_site_slug_ru', columns: ['site_id', 'slug_ru'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_site_slug_en', columns: ['site_id', 'slug_en'])]
#[ORM\UniqueConstraint(name: 'uniq_svc_post_site_studio_group', columns: ['site_id', 'studio_group_id'])]
#[ORM\Index(columns: ['site_id', 'active', 'published_at'], name: 'idx_svc_post_site_published')]
#[Vich\Uploadable]
class ServicePost implements TimestampableInterface
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

    /** id статті iblog у Joomla-джерелі (ідемпотентний імпорт) */
    #[ORM\Column(nullable: true)]
    private ?int $legacyId = null;

    /** groupId статті в content-studio (satellit): спільний для uk/ru, ключ upsert приймача /api/studio */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $studioGroupId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $studioSyncedAt = null;

    /**
     * Мова статті студії, якою задано набір тегів (24.10.2026): PUT цією мовою замінює набір, PUT іншою мовою — переклад, лише
     * дописує назви тегів (студія пише оригінал мовою свого сайту, а в переклад шле tags: []). NULL — тегів зі студії ще не було.
     */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $studioTagsLang = null;

    /** NULL — статті нема українською (англомовний бренд; 22.10.2026): кілька таких на сайті не конфліктують в унікальному індексі */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $slugUk = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $slugRu = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleRu = null;

    /** Лід-анонс (стрічка + meta description) */
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[Assert\Image(maxSize: '10M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 8000, maxHeight: 8000, mimeTypesMessage: 'Дозволено лише JPG/PNG/WebP.')]
    #[Vich\UploadableField(mapping: 'service_post_media', fileNameProperty: 'imageName')]
    private ?File $imageFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAlt = null;

    /** alt обкладинки для ru (null = фолбек на imageAlt); панель content-studio пише по мовах */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAltRu = null;

    // контентний сайт (22.10.2026): рубрика, автор, «обрана» на головній; видалення рубрики/автора лишає статтю без них
    #[ORM\ManyToOne(targetEntity: ServicePostCategory::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServicePostCategory $category = null;

    #[ORM\ManyToOne(targetEntity: ServiceAuthor::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServiceAuthor $author = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $featured = false;

    /**
     * Теги статті (24.10.2026, контентний сайт): люди з прямою мовою від content-studio, до 3; незалежні від рубрики.
     * Видалення тегу чи статті прибирає лише зв'язок.
     *
     * @var Collection<int, ServicePostTag>
     */
    #[ORM\ManyToMany(targetEntity: ServicePostTag::class)]
    #[ORM\JoinTable(name: 'service_post_tags')]
    #[ORM\JoinColumn(name: 'post_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $tags;

    // англійська версія (22.10.2026): без фолбеку на uk — стаття існує англійською, лише коли є slugEn і titleEn
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $slugEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titleEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $leadEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $descEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageAltEn = null;

    /**
     * Дата змістовного оновлення (25.10.2026): ставить PostContentDateListener, коли по суті змінився заголовок, лід чи текст
     * будь-якою мовою (типографіка, теги, обкладинка, повторна публікація без змін — ні). NULL — не оновлювалась. updatedAt —
     * технічна мітка (будь-який запис, імпорт), для dateModified і «Updated» не годиться.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $contentUpdatedAt = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
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
        return $this->slugUk ?? '';
    }

    /** '' = NULL: статті нема українською */
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
        $this->slugRu = $slugRu;

        return $this;
    }

    public function getTitleUk(): string
    {
        return $this->titleUk ?? '';
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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

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

    public function getStudioGroupId(): ?string
    {
        return $this->studioGroupId;
    }

    public function setStudioGroupId(?string $studioGroupId): self
    {
        $this->studioGroupId = $studioGroupId;

        return $this;
    }

    public function getStudioSyncedAt(): ?\DateTimeImmutable
    {
        return $this->studioSyncedAt;
    }

    public function getContentUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->contentUpdatedAt;
    }

    public function setContentUpdatedAt(?\DateTimeImmutable $contentUpdatedAt): self
    {
        $this->contentUpdatedAt = $contentUpdatedAt;

        return $this;
    }

    /**
     * Дата для dateModified і article:modified_time: змістовне оновлення, якщо воно пізніше за публікацію, інакше дата
     * публікації (імпорт і технічні записи її не зсувають).
     */
    public function modifiedAt(): ?\DateTimeInterface
    {
        if ($this->contentUpdatedAt !== null && ($this->publishedAt === null || $this->contentUpdatedAt > $this->publishedAt)) {
            return $this->contentUpdatedAt;
        }

        return $this->publishedAt;
    }

    /** Показувати «Updated …»: змістовне оновлення пізніше за публікацію більш ніж на добу */
    public function isUpdated(): bool
    {
        return $this->publishedAt !== null && $this->contentUpdatedAt !== null
            && $this->contentUpdatedAt->getTimestamp() - $this->publishedAt->getTimestamp() > 86400;
    }

    public function getStudioTagsLang(): ?string
    {
        return $this->studioTagsLang;
    }

    public function setStudioTagsLang(?string $studioTagsLang): self
    {
        $this->studioTagsLang = $studioTagsLang;

        return $this;
    }

    public function setStudioSyncedAt(?\DateTimeImmutable $studioSyncedAt): self
    {
        $this->studioSyncedAt = $studioSyncedAt;

        return $this;
    }

    public function getImageAltRu(): ?string
    {
        return $this->imageAltRu;
    }

    public function setImageAltRu(?string $imageAltRu): self
    {
        $this->imageAltRu = $imageAltRu;

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

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function setTitleEn(?string $titleEn): self
    {
        $this->titleEn = $titleEn === '' ? null : $titleEn;

        return $this;
    }

    public function getLeadEn(): ?string
    {
        return $this->leadEn;
    }

    public function setLeadEn(?string $leadEn): self
    {
        $this->leadEn = $leadEn === '' ? null : $leadEn;

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

    public function getMetaTitleEn(): ?string
    {
        return $this->metaTitleEn;
    }

    public function setMetaTitleEn(?string $metaTitleEn): self
    {
        $this->metaTitleEn = $metaTitleEn === '' ? null : $metaTitleEn;

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

    public function getImageAltEn(): ?string
    {
        return $this->imageAltEn;
    }

    public function setImageAltEn(?string $imageAltEn): self
    {
        $this->imageAltEn = $imageAltEn === '' ? null : $imageAltEn;

        return $this;
    }

    public function getCategory(): ?ServicePostCategory
    {
        return $this->category;
    }

    public function setCategory(?ServicePostCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getAuthor(): ?ServiceAuthor
    {
        return $this->author;
    }

    public function setAuthor(?ServiceAuthor $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): self
    {
        $this->featured = $featured;

        return $this;
    }

    /** @return Collection<int, ServicePostTag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(ServicePostTag $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(ServicePostTag $tag): self
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    /**
     * Повна заміна набору тегів (приймач студії): [] — без тегів.
     *
     * @param iterable<ServicePostTag> $tags
     */
    public function replaceTags(iterable $tags): self
    {
        $keep = [];
        foreach ($tags as $tag) {
            $keep[spl_object_id($tag)] = $tag;
        }
        foreach ($this->tags->toArray() as $tag) {
            if (!isset($keep[spl_object_id($tag)])) {
                $this->tags->removeElement($tag);
            }
        }
        foreach ($keep as $tag) {
            $this->addTag($tag);
        }

        return $this;
    }

    /**
     * Теги для показу мовою: активні й ті, що існують мовою (шаблони статті, JSON-LD).
     *
     * @return list<ServicePostTag>
     */
    public function tagsIn(string $locale): array
    {
        return array_values(array_filter($this->tags->toArray(), static fn (ServicePostTag $t): bool => $t->isActive() && $t->existsIn($locale)));
    }

    /** alt за локаллю з фолбеком ru→uk (у шаблонах: post.imageAlt(locale)); en — без фолбеку */
    public function imageAlt(string $locale): ?string
    {
        if ($locale === 'en') {
            return $this->imageAltEn;
        }

        return $locale === 'ru' && $this->imageAltRu !== null && $this->imageAltRu !== '' ? $this->imageAltRu : $this->imageAlt;
    }

    /**
     * Чи існує стаття цією мовою: є слаг і заголовок (ru — з фолбеком на uk, як і раніше). Той самий предикат —
     * у ServicePostRepository::localeCondition() для списків, sitemap і пошуку за слагом.
     */
    public function existsIn(string $locale): bool
    {
        return trim((string) $this->loc('slug', $locale)) !== '' && trim((string) $this->loc('title', $locale)) !== '';
    }

    /** Стаття має хоч одну мову (адмінка, студія) */
    #[Assert\Callback]
    public function validateHasLanguage(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        foreach (['uk', 'en'] as $l) {
            if ($this->existsIn($l)) {
                return;
            }
        }
        $context->buildViolation('Потрібні заголовок і слаг хоча б однією мовою.')->atPath('titleUk')->addViolation();
    }

    /** Теги статті (адмінка): лише теги того самого бренду, не більше 3 — як приймає студія */
    #[Assert\Callback]
    public function validateTags(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->tags->count() > 3) {
            $context->buildViolation('Не більше 3 тегів.')->atPath('tags')->addViolation();
        }
        foreach ($this->tags as $tag) {
            if ($this->site !== null && $tag->getSite()?->getId() !== $this->site->getId()) {
                $context->buildViolation(sprintf('Тег «%s» — іншого бренду.', $tag))->atPath('tags')->addViolation();
            }
        }
    }

    /** Слаг за локаллю з фолбеком ru→uk */
    public function slug(string $locale): string
    {
        return (string) $this->loc('slug', $locale);
    }

    public function __toString(): string
    {
        return $this->titleUk ?? $this->titleEn ?? '';
    }
}
