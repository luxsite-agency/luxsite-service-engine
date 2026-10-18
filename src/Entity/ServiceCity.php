<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceCityRepository;
use App\ServiceSite\LocalizedFieldsTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Місто-присутність сервісного сайту = один Host (аналог Seblod-рядка `domains`
 * у Joomla-сайтах): апекс для головного міста, піддомен або окремий домен для решти.
 * Каталог послуг спільний на весь сайт; per-місто — контакти, тексти головної,
 * ціни, райони, команда.
 *
 * Відмінки назви міста потрібні генерації текстів («у Києві», «до Дніпра»).
 */
#[ORM\Entity(repositoryClass: ServiceCityRepository::class)]
#[ORM\Table(name: 'service_city')]
#[ORM\UniqueConstraint(name: 'uniq_svc_city_site_slug', columns: ['site_id', 'slug'])]
class ServiceCity implements TimestampableInterface
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

    /** Прод-хост міста: апекс (example.com.ua), піддомен чи будь-який окремий домен */
    #[ORM\Column(length: 128, unique: true)]
    private string $host;

    /** Латинський ідентифікатор; збігається з dev-піддоменом <slug>.<site>.localhost */
    #[ORM\Column(length: 64)]
    private string $slug;

    /** Головне місто бренду — живе на апекс-домені й dev-хості без піддомена */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isApex = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    /** Публічний email міста (футер, сторінка контактів) */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $email = null;

    /**
     * Месенджери міста (шапка біля телефону, футер, контакти, модалка заявки); порожньо = посилання нема.
     * viber/whatsapp — номер у міжнародному форматі без «+» (380969293247): viber://chat?number=…, https://wa.me/…;
     * telegram — username без «@»: https://t.me/…. Сеттери нормалізують («+380 96…», «@name», «t.me/name»).
     * Клік → dataLayer messenger_click (base.html.twig); у чужому GTM тригер може бути й на href (напр. click_viber).
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Regex(pattern: '/^[0-9]{7,15}$/', message: 'Viber: номер у міжнародному форматі без «+», лише цифри (380969293247).')]
    private ?string $viber = null;

    #[ORM\Column(length: 64, nullable: true)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_]{5,32}$/', message: 'Telegram: username без «@» (5–32 символи, латиниця, цифри, _).')]
    private ?string $telegram = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Regex(pattern: '/^[0-9]{7,15}$/', message: 'WhatsApp: номер у міжнародному форматі без «+», лише цифри (380969293247).')]
    private ?string $whatsapp = null;

    /** Службова пересилка заявок: адреси через кому; порожньо = на публічний email */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $leadEmails = null;

    /** Години роботи текстом («Пн–Нд 8:00–20:00») */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $hours = null;

    /** Адреса офісу текстом для футера й розмітки; порожньо - не показуємо */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressRu = null;

    /**
     * Абзац «Де ми працюємо» для сторінки «Про нас». Сам текст «Про нас» спільний
     * для всіх міст і написаний під Київ, тому райони й передмістя живуть тут,
     * а в спільному тексті стоїть маркер <!--city-where-->.
     */
    /** «Про нас» під місто (10.2026): повний текст замість спільного ServiceSite.about; порожньо = спільний */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutWhereUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aboutWhereRu = null;

    /**
     * Ідентифікатор лічильника: GTM-XXXXXXX (Tag Manager), G-XXXXXXXXXX (GA4
     * напряму) або AW-XXXXXXXXX (Google Ads). null = лічильника немає.
     *
     * У поле можна вставити цілий код, який дає Google, - сетер сам дістане
     * звідти ідентифікатор. Це не лише зручність: у шаблоні значення
     * підставляється всередину <script>, тож брати з нього тільки
     * ідентифікатор безпечніше, ніж довіряти вмісту поля.
     */
    #[ORM\Column(length: 16, nullable: true)]
    #[Assert\Regex(
        pattern: '/^(GTM-[A-Z0-9]{4,12}|G-[A-Z0-9]{6,14}|AW-[0-9]{6,14})$/',
        message: 'Не вдалось розпізнати ідентифікатор. Очікую GTM-XXXXXXX, G-XXXXXXXXXX або AW-XXXXXXXXX.',
    )]
    private ?string $gtmId = null;

    /**
     * Готове посилання на вбудовану Google-карту (…&output=embed або src із
     * «Поділитися → Вставити карту»). Порожньо - блок карти на контактах не показуємо.
     */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $mapEmbedUrl = null;

    /** Посилання на сам листинг у Google (кнопка «Відкрити на Google Картах»). */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $mapLinkUrl = null;

    /** Місто в розробці: закрите від пошуковиків (meta robots + robots.txt). */
    #[ORM\Column(options: ['default' => false])]
    private bool $noindex = false;

    // — Назва міста у відмінках (наз./род./дав./місц.), uk та ru —

    #[ORM\Column(length: 64)]
    private string $nameNomUk = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $nameGenUk = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $nameDatUk = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $nameLocUk = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $nameNomRu = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $nameGenRu = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $nameDatRu = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $nameLocRu = null;

    // — Мета головної сторінки —

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $metaTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $metaDescRu = null;

    // — Текстові блоки головної —

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $introTitleUk = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $introTitleRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $introTextUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $introTextRu = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text2Uk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text2Ru = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text3Uk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text3Ru = null;

    /**
     * FAQ-блок головної: [{q, a}, ...]
     *
     * @var list<array{q: string, a: string}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqUk = null;

    /** @var list<array{q: string, a: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $faqRu = null;

    /** Інтро над акордеоном районів */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $districtsIntroUk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $districtsIntroRu = null;

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

    public function getHost(): string
    {
        return $this->host;
    }

    public function setHost(string $host): self
    {
        $this->host = strtolower($host);

        return $this;
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

    public function isApex(): bool
    {
        return $this->isApex;
    }

    public function setIsApex(bool $isApex): self
    {
        $this->isApex = $isApex;

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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getViber(): ?string
    {
        return $this->viber;
    }

    public function setViber(?string $viber): self
    {
        $this->viber = self::digits($viber);

        return $this;
    }

    public function getTelegram(): ?string
    {
        return $this->telegram;
    }

    public function setTelegram(?string $telegram): self
    {
        $t = trim((string) $telegram);
        $t = preg_replace('~^(?:https?://)?(?:t\.me|telegram\.me)/~i', '', $t) ?? $t;
        $this->telegram = ltrim($t, '@') !== '' ? ltrim($t, '@') : null;

        return $this;
    }

    public function getWhatsapp(): ?string
    {
        return $this->whatsapp;
    }

    public function setWhatsapp(?string $whatsapp): self
    {
        $this->whatsapp = self::digits($whatsapp);

        return $this;
    }

    public function hasMessengers(): bool
    {
        return $this->viber !== null || $this->telegram !== null || $this->whatsapp !== null;
    }

    /** «+380 (96) 929-32-47» → «380969293247»; порожньо → null */
    private static function digits(?string $v): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $v) ?? '';

        return $d !== '' ? $d : null;
    }

    public function getLeadEmails(): ?string
    {
        return $this->leadEmails;
    }

    public function setLeadEmails(?string $leadEmails): self
    {
        $this->leadEmails = $leadEmails;

        return $this;
    }

    public function getAddressUk(): ?string
    {
        return $this->addressUk;
    }

    public function setAddressUk(?string $addressUk): self
    {
        $this->addressUk = $addressUk;

        return $this;
    }

    public function getAddressRu(): ?string
    {
        return $this->addressRu;
    }

    public function setAddressRu(?string $addressRu): self
    {
        $this->addressRu = $addressRu;

        return $this;
    }

    public function getAboutWhereUk(): ?string
    {
        return $this->aboutWhereUk;
    }

    public function setAboutWhereUk(?string $aboutWhereUk): self
    {
        $this->aboutWhereUk = $aboutWhereUk;

        return $this;
    }

    public function getAboutWhereRu(): ?string
    {
        return $this->aboutWhereRu;
    }

    public function setAboutWhereRu(?string $aboutWhereRu): self
    {
        $this->aboutWhereRu = $aboutWhereRu;

        return $this;
    }

    /** Адреса мовою сторінки; ru порожня - беремо uk */
    public function address(string $locale): ?string
    {
        return $this->loc('address', $locale) ?: $this->addressUk;
    }

    public function getHours(): ?string
    {
        return $this->hours;
    }

    public function setHours(?string $hours): self
    {
        $this->hours = $hours;

        return $this;
    }

    public function getGtmId(): ?string
    {
        return $this->gtmId;
    }

    public function setGtmId(?string $gtmId): self
    {
        $this->gtmId = self::extractTagId($gtmId);

        return $this;
    }

    /**
     * Дістає ідентифікатор із того, що вставили: чистий ID, цілий сніпет
     * Google, посилання на gtm.js - усе одно знайде. Якщо не знайшло,
     * повертає введене як є, щоб людина побачила свою помилку в полі,
     * а не порожнечу.
     */
    public static function extractTagId(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/(GTM-[A-Z0-9]{4,12}|G-[A-Z0-9]{6,14}|AW-[0-9]{6,14})/i', $raw, $m) === 1) {
            return strtoupper($m[1]);
        }

        return mb_substr($raw, 0, 16);
    }

    /** Тип лічильника - шаблон за ним обирає, який код вставляти */
    public function getTagKind(): ?string
    {
        return match (true) {
            $this->gtmId === null => null,
            str_starts_with($this->gtmId, 'GTM-') => 'gtm',
            str_starts_with($this->gtmId, 'G-'), str_starts_with($this->gtmId, 'AW-') => 'gtag',
            default => null,
        };
    }

    /**
     * Назва міста в називному відмінку потрібною мовою.
     *
     * Шаблони кличуть `city.name(locale)` у 45 місцях, але методу не було:
     * Twig у проді мовчки віддавав null, і порожнє значення просто не було
     * помітним. Щойно з'явились міські тексти послуг, виконалась гілка, де
     * це вже кидало помилку і клало всі сторінки послуг.
     */
    public function name(string $locale): string
    {
        return (string) ($this->loc('nameNom', $locale) ?: $this->nameNomUk);
    }

    public function getNameNomUk(): string
    {
        return $this->nameNomUk;
    }

    public function setNameNomUk(string $nameNomUk): self
    {
        $this->nameNomUk = $nameNomUk;

        return $this;
    }

    public function getNameGenUk(): ?string
    {
        return $this->nameGenUk;
    }

    public function setNameGenUk(?string $nameGenUk): self
    {
        $this->nameGenUk = $nameGenUk;

        return $this;
    }

    public function getNameDatUk(): ?string
    {
        return $this->nameDatUk;
    }

    public function setNameDatUk(?string $nameDatUk): self
    {
        $this->nameDatUk = $nameDatUk;

        return $this;
    }

    public function getNameLocUk(): ?string
    {
        return $this->nameLocUk;
    }

    public function setNameLocUk(?string $nameLocUk): self
    {
        $this->nameLocUk = $nameLocUk;

        return $this;
    }

    public function getNameNomRu(): ?string
    {
        return $this->nameNomRu;
    }

    public function setNameNomRu(?string $nameNomRu): self
    {
        $this->nameNomRu = $nameNomRu;

        return $this;
    }

    public function getNameGenRu(): ?string
    {
        return $this->nameGenRu;
    }

    public function setNameGenRu(?string $nameGenRu): self
    {
        $this->nameGenRu = $nameGenRu;

        return $this;
    }

    public function getNameDatRu(): ?string
    {
        return $this->nameDatRu;
    }

    public function setNameDatRu(?string $nameDatRu): self
    {
        $this->nameDatRu = $nameDatRu;

        return $this;
    }

    public function getNameLocRu(): ?string
    {
        return $this->nameLocRu;
    }

    public function setNameLocRu(?string $nameLocRu): self
    {
        $this->nameLocRu = $nameLocRu;

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

    public function getIntroTitleUk(): ?string
    {
        return $this->introTitleUk;
    }

    public function setIntroTitleUk(?string $introTitleUk): self
    {
        $this->introTitleUk = $introTitleUk;

        return $this;
    }

    public function getIntroTitleRu(): ?string
    {
        return $this->introTitleRu;
    }

    public function setIntroTitleRu(?string $introTitleRu): self
    {
        $this->introTitleRu = $introTitleRu;

        return $this;
    }

    public function getIntroTextUk(): ?string
    {
        return $this->introTextUk;
    }

    public function setIntroTextUk(?string $introTextUk): self
    {
        $this->introTextUk = $introTextUk;

        return $this;
    }

    public function getIntroTextRu(): ?string
    {
        return $this->introTextRu;
    }

    public function setIntroTextRu(?string $introTextRu): self
    {
        $this->introTextRu = $introTextRu;

        return $this;
    }

    public function getText2Uk(): ?string
    {
        return $this->text2Uk;
    }

    public function setText2Uk(?string $text2Uk): self
    {
        $this->text2Uk = $text2Uk;

        return $this;
    }

    public function getText2Ru(): ?string
    {
        return $this->text2Ru;
    }

    public function setText2Ru(?string $text2Ru): self
    {
        $this->text2Ru = $text2Ru;

        return $this;
    }

    public function getText3Uk(): ?string
    {
        return $this->text3Uk;
    }

    public function setText3Uk(?string $text3Uk): self
    {
        $this->text3Uk = $text3Uk;

        return $this;
    }

    public function getText3Ru(): ?string
    {
        return $this->text3Ru;
    }

    public function setText3Ru(?string $text3Ru): self
    {
        $this->text3Ru = $text3Ru;

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

    public function getDistrictsIntroUk(): ?string
    {
        return $this->districtsIntroUk;
    }

    public function setDistrictsIntroUk(?string $districtsIntroUk): self
    {
        $this->districtsIntroUk = $districtsIntroUk;

        return $this;
    }

    public function getDistrictsIntroRu(): ?string
    {
        return $this->districtsIntroRu;
    }

    public function setDistrictsIntroRu(?string $districtsIntroRu): self
    {
        $this->districtsIntroRu = $districtsIntroRu;

        return $this;
    }

    /** Назва міста за локаллю (наз. відмінок) з фолбеком ru→uk */
    public function getName(string $locale = 'uk'): string
    {
        return (string) $this->loc('nameNom', $locale);
    }

    public function getMapEmbedUrl(): ?string
    {
        return $this->mapEmbedUrl;
    }

    public function setMapEmbedUrl(?string $mapEmbedUrl): self
    {
        $this->mapEmbedUrl = $mapEmbedUrl;

        return $this;
    }

    public function getMapLinkUrl(): ?string
    {
        return $this->mapLinkUrl;
    }

    public function setMapLinkUrl(?string $mapLinkUrl): self
    {
        $this->mapLinkUrl = $mapLinkUrl;

        return $this;
    }

    public function isNoindex(): bool
    {
        return $this->noindex;
    }

    public function setNoindex(bool $noindex): self
    {
        $this->noindex = $noindex;

        return $this;
    }

    public function __toString(): string
    {
        return $this->nameNomUk . ' (' . $this->host . ')';
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
}
