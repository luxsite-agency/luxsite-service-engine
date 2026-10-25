<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceSiteRepository;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Сервісний сайт-бренд (ніша): електрики, клінінг, сантехніки - один рушій, пак на бренд.
 * Профіль «сервісних сайтів» живе поруч із міськими сайтами (City) і
 * резолвиться по Host окремим ServiceHostResolver.
 */
#[ORM\Entity(repositoryClass: ServiceSiteRepository::class)]
#[ORM\Table(name: 'service_site')]
class ServiceSite implements TimestampableInterface
{
    public const MODE_SERVICES = 'services';
    public const MODE_CONTENT = 'content';

    public const THUMBS_OFF = 'off';
    public const THUMBS_HOME = 'home';
    public const THUMBS_LISTS = 'lists';
    public const THUMBS_ALL = 'all';

    use LocalizedFieldsTrait;
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Латинський ідентифікатор ніші; збігається з dev-хостом <slug>.localhost */
    #[ORM\Column(length: 64, unique: true)]
    private string $slug;

    #[ORM\Column(length: 128)]
    private string $name;

    /** Апекс-домен бренду (example.com.ua) — на ньому живе головне місто */
    #[ORM\Column(length: 128, unique: true)]
    private string $apexDomain;

    /**
     * Коротка назва бренду для текстів (власник 18.10.2026: «Відгуки клієнтів про компанію %site%»). Порожньо —
     * береться параметр паку app.brand_name, далі назва сайту. Потрібна, коли назва — загальне слово
     * («Електрики» → «Електрики» в лапках) або різниться за мовою.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $brandNameUk = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $brandNameRu = null;

    /**
     * Підтип LocalBusiness для JSON-LD (Electrician, Plumber, HouseCleaning...).
     * Платформа мультинішева — тип схеми не хардкодиться в шаблонах.
     */
    #[ORM\Column(length: 64, options: ['default' => 'LocalBusiness'])]
    private string $schemaType = 'LocalBusiness';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /**
     * Мови бренду (22.10.2026): підмножина мов розгортання app.locales у їхньому порядку; null = усі мови розгортання
     * (так працюють сайти, створені до цього поля). Мова не з набору → 404, hreflang/sitemap/перемикач — лише ці мови.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $locales = null;

    /**
     * Режим сайту (22.10.2026): services — сервісний сайт (каталог, ціни, райони, заявки; так працювали всі сайти до
     * цього поля); content — контентний (блог з рубриками й авторами, «Про нас», форма зв'язку; решта маршрутів — 404).
     */
    #[ORM\Column(length: 16, options: ['default' => 'services'])]
    private string $mode = self::MODE_SERVICES;

    /**
     * Ілюстрація хіро головної (шлях від кореня public, напр. /themes/<key>/hero.webp).
     * Векторна бренд-графіка живе в репо; фото-контент піде через Vich окремо.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $heroImagePath = null;

    /**
     * Розділ статей бренду (24.10.2026): /<префікс>/… замість app.blog_prefix — кілька брендів одного розгортання з
     * різними розділами (англійські /blog/, українські /novyny/). null = app.blog_prefix, як було.
     */
    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Лише латиниця в нижньому регістрі, цифри й дефіс: blog, novyny.')]
    private ?string $blogPrefix = null;

    /**
     * Прев'ю зображень статей у списках контентного сайту (25.10.2026, перемикач в адмінці без деплою): off — без мініатюр
     * (типово, як було), home — лише головна, lists — стрічка розділу статей, рубрики, теги й автори, all — скрізь.
     * Мініатюра — лише в статті з обкладинкою; без обкладинки рядок як без прев'ю.
     */
    #[ORM\Column(length: 8, options: ['default' => 'off'])]
    private string $listThumbs = self::THUMBS_OFF;

    // — Сторінка «Про нас» (спільна на всі міста бренду) —

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aboutTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aboutTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutRu = null;

    // - Юридичні сторінки (спільні на всі міста бренду) -

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privacyUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privacyRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $termsUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $termsRu = null;

    // англійська версія (22.10.2026): без фолбеку на uk
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $brandNameEn = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aboutTitleEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privacyEn = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $termsEn = null;

    /**
     * Сирий текст «Про нас» для мови сторінки: uk і ru — українське поле (так сторінку й пункт меню вмикали до 22.10.2026),
     * en — англійське, без фолбеку. null = сторінки цією мовою нема.
     */
    public function aboutFor(string $locale): ?string
    {
        return $this->locRaw('about', $locale === 'ru' ? 'uk' : $locale);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBrandNameUk(): ?string
    {
        return $this->brandNameUk;
    }

    public function setBrandNameUk(?string $brandNameUk): self
    {
        $this->brandNameUk = null !== $brandNameUk && '' !== trim($brandNameUk) ? trim($brandNameUk) : null;

        return $this;
    }

    public function getBrandNameRu(): ?string
    {
        return $this->brandNameRu;
    }

    public function setBrandNameRu(?string $brandNameRu): self
    {
        $this->brandNameRu = null !== $brandNameRu && '' !== trim($brandNameRu) ? trim($brandNameRu) : null;

        return $this;
    }

    /** Коротка назва бренду з адмінки мовою сторінки (ru → uk) або null — тоді вирішує site_brand(). */
    public function brandName(string $locale): ?string
    {
        return 'ru' === $locale ? ($this->brandNameRu ?? $this->brandNameUk) : $this->brandNameUk;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getApexDomain(): string
    {
        return $this->apexDomain;
    }

    public function setApexDomain(string $apexDomain): self
    {
        $this->apexDomain = $apexDomain;

        return $this;
    }

    public function getSchemaType(): string
    {
        return $this->schemaType;
    }

    public function setSchemaType(string $schemaType): self
    {
        $this->schemaType = $schemaType;

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

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        if (!\in_array($mode, [self::MODE_SERVICES, self::MODE_CONTENT], true)) {
            throw new \InvalidArgumentException(sprintf('Режим сайту: services або content, отримано «%s».', $mode));
        }
        $this->mode = $mode;

        return $this;
    }

    public function isContent(): bool
    {
        return $this->mode === self::MODE_CONTENT;
    }

    /** Англійська є лише в контентному режимі: каталог (послуги, ціни, райони) англійською не перекладено */
    #[Assert\Callback]
    public function validateEnglishIsContent(ExecutionContextInterface $context): void
    {
        if (\in_array('en', $this->locales ?? [], true) && !$this->isContent()) {
            $context->buildViolation('Англійська мова доступна лише контентному сайту (режим «Контентний»).')->atPath('locales')->addViolation();
        }
    }

    /** Префікс розділу статей не може бути фіксованим розділом сайту (services, about…), окрім blog і news */
    #[Assert\Callback]
    public function validateBlogPrefix(ExecutionContextInterface $context): void
    {
        if ($this->blogPrefix !== null && \in_array($this->blogPrefix, array_diff(CatalogUrls::RESERVED_SEGMENTS, ['blog', 'news']), true)) {
            $context->buildViolation('«{{ value }}» — зарезервований сегмент іншого розділу сайту.')->setParameter('{{ value }}', $this->blogPrefix)->atPath('blogPrefix')->addViolation();
        }
    }

    public function getBlogPrefix(): ?string
    {
        return $this->blogPrefix;
    }

    public function getListThumbs(): string
    {
        return $this->listThumbs;
    }

    public function setListThumbs(?string $listThumbs): self
    {
        $listThumbs = $listThumbs ?: self::THUMBS_OFF;
        if (!\in_array($listThumbs, [self::THUMBS_OFF, self::THUMBS_HOME, self::THUMBS_LISTS, self::THUMBS_ALL], true)) {
            throw new \InvalidArgumentException(sprintf('Прев\'ю зображень: off, home, lists або all, отримано «%s».', $listThumbs));
        }
        $this->listThumbs = $listThumbs;

        return $this;
    }

    /** Чи показувати мініатюри статей на сторінці: home — головна, lists — стрічка, рубрика, тег, автор; інше — false */
    public function showsThumbs(string $where): bool
    {
        if (!\in_array($where, [self::THUMBS_HOME, self::THUMBS_LISTS], true)) {
            return false;
        }

        return $this->listThumbs === self::THUMBS_ALL || $this->listThumbs === $where;
    }

    public function setBlogPrefix(?string $blogPrefix): self
    {
        $blogPrefix = $blogPrefix === null ? '' : trim($blogPrefix, " /\t\n");
        $this->blogPrefix = $blogPrefix === '' ? null : $blogPrefix;

        return $this;
    }

    /** @return list<string>|null */
    public function getLocales(): ?array
    {
        return $this->locales;
    }

    /** @param list<string>|null $locales порожній список = null (усі мови розгортання) */
    public function setLocales(?array $locales): self
    {
        $locales = $locales === null ? [] : array_values(array_unique(array_filter($locales, 'is_string')));
        $this->locales = $locales === [] ? null : $locales;

        return $this;
    }

    /**
     * Увімкнені мови бренду в порядку мов розгортання (перша — без префікса).
     *
     * @param list<string> $deployment app.locales
     *
     * @return list<string>
     */
    public function enabledLocales(array $deployment): array
    {
        if ($this->locales === null) {
            return $deployment;
        }

        return array_values(array_intersect($deployment, $this->locales));
    }

    public function getHeroImagePath(): ?string
    {
        return $this->heroImagePath;
    }

    public function setHeroImagePath(?string $heroImagePath): self
    {
        $this->heroImagePath = $heroImagePath;

        return $this;
    }

    public function getAboutTitleUk(): ?string
    {
        return $this->aboutTitleUk;
    }

    public function setAboutTitleUk(?string $aboutTitleUk): self
    {
        $this->aboutTitleUk = $aboutTitleUk;

        return $this;
    }

    public function getAboutTitleRu(): ?string
    {
        return $this->aboutTitleRu;
    }

    public function setAboutTitleRu(?string $aboutTitleRu): self
    {
        $this->aboutTitleRu = $aboutTitleRu;

        return $this;
    }

    public function getAboutUk(): ?string
    {
        return $this->aboutUk;
    }

    public function setAboutUk(?string $aboutUk): self
    {
        $this->aboutUk = $aboutUk;

        return $this;
    }

    public function getAboutRu(): ?string
    {
        return $this->aboutRu;
    }

    public function setAboutRu(?string $aboutRu): self
    {
        $this->aboutRu = $aboutRu;

        return $this;
    }

    public function getPrivacyUk(): ?string
    {
        return $this->privacyUk;
    }

    public function setPrivacyUk(?string $privacyUk): self
    {
        $this->privacyUk = $privacyUk;

        return $this;
    }

    public function getPrivacyRu(): ?string
    {
        return $this->privacyRu;
    }

    public function setPrivacyRu(?string $privacyRu): self
    {
        $this->privacyRu = $privacyRu;

        return $this;
    }

    public function getTermsUk(): ?string
    {
        return $this->termsUk;
    }

    public function setTermsUk(?string $termsUk): self
    {
        $this->termsUk = $termsUk;

        return $this;
    }

    public function getTermsRu(): ?string
    {
        return $this->termsRu;
    }

    public function setTermsRu(?string $termsRu): self
    {
        $this->termsRu = $termsRu;

        return $this;
    }

    public function getBrandNameEn(): ?string
    {
        return $this->brandNameEn;
    }

    public function setBrandNameEn(?string $brandNameEn): self
    {
        $this->brandNameEn = $brandNameEn === '' ? null : $brandNameEn;

        return $this;
    }

    public function getAboutTitleEn(): ?string
    {
        return $this->aboutTitleEn;
    }

    public function setAboutTitleEn(?string $aboutTitleEn): self
    {
        $this->aboutTitleEn = $aboutTitleEn === '' ? null : $aboutTitleEn;

        return $this;
    }

    public function getAboutEn(): ?string
    {
        return $this->aboutEn;
    }

    public function setAboutEn(?string $aboutEn): self
    {
        $this->aboutEn = $aboutEn === '' ? null : $aboutEn;

        return $this;
    }

    public function getPrivacyEn(): ?string
    {
        return $this->privacyEn;
    }

    public function setPrivacyEn(?string $privacyEn): self
    {
        $this->privacyEn = $privacyEn === '' ? null : $privacyEn;

        return $this;
    }

    public function getTermsEn(): ?string
    {
        return $this->termsEn;
    }

    public function setTermsEn(?string $termsEn): self
    {
        $this->termsEn = $termsEn === '' ? null : $termsEn;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
