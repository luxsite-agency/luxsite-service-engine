<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServiceLeadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Заявка з форми (модалка/інлайн). На відміну від Joomla-версії — персиститься
 * в БД і маршрутизується по місту (email міста), а не на хардкод-адреси.
 */
#[ORM\Entity(repositoryClass: ServiceLeadRepository::class)]
#[ORM\Table(name: 'service_lead')]
#[ORM\Index(columns: ['city_id', 'processed', 'created_at'], name: 'idx_svc_lead_city_processed')]
#[ORM\Index(columns: ['crm_external_id', 'created_at'], name: 'idx_svc_lead_crm')]
class ServiceLead implements TimestampableInterface
{
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

    #[ORM\Column(length: 32)]
    private string $phone = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** Сторінка, з якої відправлено форму */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $pageUrl = null;

    #[ORM\Column(length: 2, options: ['default' => 'uk'])]
    private string $locale = 'uk';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $processed = false;

    // - Джерело переходу: заповнюється прихованими полями форми -

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $utmSource = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $utmMedium = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $utmCampaign = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $utmContent = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $utmTerm = null;

    /** gclid, fbclid тощо - ідентифікатор кліку рекламної системи. */
    #[ORM\Column(length: 191, nullable: true)]
    private ?string $clickId = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $referrer = null;

    /** Перша сторінка візиту - показує, який матеріал привів людину. */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $landingUrl = null;

    /**
     * Додаткові поля форми (app.lead_fields паку — запис на прийом, запит на розрахунок): {ключ: значення}; select —
     * підпис обраного варіанта українською, date — Y-m-d. Читабельна копія — у message. null = форма без додаткових полів.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    /** Коли людина поставила галочку згоди на обробку персональних даних (app.lead_consent); null — згоду не питали. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consentAt = null;

    // - CRM сайту (app.crm, конектор App\ServiceSite\Crm\LeadCrmConnector) -

    /** Ід запису в CRM (лід Pipedrive - uuid, угода Bitrix24 - число); null - ще не відправлено, черга app:crm:sync. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $crmExternalId = null;

    /** Код CRM, у яку пішла заявка (pipedrive, bitrix24): після зміни CRM сайту видно, де шукати старі записи. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $crmSystem = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $crmSyncedAt = null;

    /** Остання помилка відправки - видно в адмінці, чекає на app:crm:sync. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $crmError = null;

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

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getPageUrl(): ?string
    {
        return $this->pageUrl;
    }

    public function setPageUrl(?string $pageUrl): self
    {
        $this->pageUrl = $pageUrl;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function isProcessed(): bool
    {
        return $this->processed;
    }

    public function setProcessed(bool $processed): self
    {
        $this->processed = $processed;

        return $this;
    }

    public function getUtmSource(): ?string
    {
        return $this->utmSource;
    }

    public function setUtmSource(?string $utmSource): self
    {
        $this->utmSource = $utmSource;

        return $this;
    }

    public function getUtmMedium(): ?string
    {
        return $this->utmMedium;
    }

    public function setUtmMedium(?string $utmMedium): self
    {
        $this->utmMedium = $utmMedium;

        return $this;
    }

    public function getUtmCampaign(): ?string
    {
        return $this->utmCampaign;
    }

    public function setUtmCampaign(?string $utmCampaign): self
    {
        $this->utmCampaign = $utmCampaign;

        return $this;
    }

    public function getUtmContent(): ?string
    {
        return $this->utmContent;
    }

    public function setUtmContent(?string $utmContent): self
    {
        $this->utmContent = $utmContent;

        return $this;
    }

    public function getUtmTerm(): ?string
    {
        return $this->utmTerm;
    }

    public function setUtmTerm(?string $utmTerm): self
    {
        $this->utmTerm = $utmTerm;

        return $this;
    }

    public function getClickId(): ?string
    {
        return $this->clickId;
    }

    public function setClickId(?string $clickId): self
    {
        $this->clickId = $clickId;

        return $this;
    }

    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    public function setReferrer(?string $referrer): self
    {
        $this->referrer = $referrer;

        return $this;
    }

    public function getLandingUrl(): ?string
    {
        return $this->landingUrl;
    }

    public function setLandingUrl(?string $landingUrl): self
    {
        $this->landingUrl = $landingUrl;

        return $this;
    }

    public function getCrmExternalId(): ?string
    {
        return $this->crmExternalId;
    }

    public function setCrmExternalId(?string $crmExternalId): self
    {
        $this->crmExternalId = $crmExternalId;

        return $this;
    }

    public function getCrmSystem(): ?string
    {
        return $this->crmSystem;
    }

    public function setCrmSystem(?string $crmSystem): self
    {
        $this->crmSystem = $crmSystem;

        return $this;
    }

    public function getCrmSyncedAt(): ?\DateTimeImmutable
    {
        return $this->crmSyncedAt;
    }

    public function setCrmSyncedAt(?\DateTimeImmutable $crmSyncedAt): self
    {
        $this->crmSyncedAt = $crmSyncedAt;

        return $this;
    }

    public function getCrmError(): ?string
    {
        return $this->crmError;
    }

    public function setCrmError(?string $crmError): self
    {
        $this->crmError = $crmError;

        return $this;
    }

    /** @return array<string, string>|null */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    /** @param array<string, string>|null $details */
    public function setDetails(?array $details): self
    {
        $this->details = $details === [] ? null : $details;

        return $this;
    }

    /** Значення додаткових полів одним рядком для списку заявок в адмінці («Консультація · 25.10.2026»). */
    public function getDetailsShort(): ?string
    {
        $values = array_filter(array_map('strval', $this->details ?? []), static fn (string $v): bool => $v !== '');

        return $values !== [] ? implode(' · ', $values) : null;
    }

    public function getConsentAt(): ?\DateTimeImmutable
    {
        return $this->consentAt;
    }

    public function setConsentAt(?\DateTimeImmutable $consentAt): self
    {
        $this->consentAt = $consentAt;

        return $this;
    }

    public function __toString(): string
    {
        return $this->phone . ($this->name ? ' - ' . $this->name : '');
    }
}
