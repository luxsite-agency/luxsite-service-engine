<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\ServicePartnerRepository;
use App\Settings\SecretBox;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ServicePartnerRepository::class)]
#[ORM\Table(name: 'service_partner')]
#[ORM\Index(columns: ['city_id'], name: 'idx_svc_partner_city')]
class ServicePartner implements TimestampableInterface
{
    use TimestampsTrait;

    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_PAUSED = 'paused';
    public const string STATUS_LEFT = 'left';
    public const string MODEL_SHARED = 'shared';
    public const string MODEL_EXCLUSIVE = 'exclusive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?ServiceCity $city = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name = '';

    #[ORM\Column(length: 16, options: ['default' => 'active'])]
    #[Assert\Choice(choices: [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_LEFT])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(options: ['default' => false])]
    private bool $isOwn = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $dailyLimit = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Assert\All([new Assert\Regex(pattern: '/^[0-9]{1,10}\*?$/')])]
    private ?array $coverageZips = null;

    #[ORM\Column(length: 512, nullable: true)]
    #[Assert\Length(max: 512)]
    private ?string $notifyEmails = null;

    #[ORM\Column(length: 512, nullable: true)]
    #[Assert\Length(max: 512)]
    #[Assert\Url(protocols: ['https'])]
    private ?string $webhookUrl = null;

    /** Лише зашифрований конверт SecretBox; відкритий секрет сюди не записується. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $webhookSecret = null;

    #[ORM\Column(length: 16, options: ['default' => 'shared'])]
    #[Assert\Choice(choices: [self::MODEL_SHARED, self::MODEL_EXCLUSIVE])]
    private string $model = self::MODEL_SHARED;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $pricePerLead = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $licenseNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isOwn(): bool
    {
        return $this->isOwn;
    }

    public function setIsOwn(bool $isOwn): self
    {
        $this->isOwn = $isOwn;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getDailyLimit(): ?int
    {
        return $this->dailyLimit;
    }

    public function setDailyLimit(?int $dailyLimit): self
    {
        $this->dailyLimit = $dailyLimit;

        return $this;
    }

    /** @return list<string>|null */
    public function getCoverageZips(): ?array
    {
        return $this->coverageZips;
    }

    /** @param list<string>|null $coverageZips */
    public function setCoverageZips(?array $coverageZips): self
    {
        $this->coverageZips = $coverageZips;

        return $this;
    }

    public function getNotifyEmails(): ?string
    {
        return $this->notifyEmails;
    }

    public function setNotifyEmails(?string $notifyEmails): self
    {
        $this->notifyEmails = $notifyEmails;

        return $this;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function setWebhookUrl(?string $url): self
    {
        $this->webhookUrl = trim($url ?? '') ?: null;

        return $this;
    }

    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    public function setWebhookSecret(?string $envelope): self
    {
        if ($envelope !== null && !SecretBox::isEnvelope($envelope)) {
            throw new \InvalidArgumentException('Секрет вебхука має бути конвертом SecretBox.');
        }
        $this->webhookSecret = $envelope;

        return $this;
    }

    public function getWebhookSecretMask(): string
    {
        return SecretBox::mask($this->webhookSecret) ?? 'не задано';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getPricePerLead(): ?int
    {
        return $this->pricePerLead;
    }

    public function setPricePerLead(?int $pricePerLead): self
    {
        $this->pricePerLead = $pricePerLead;

        return $this;
    }

    public function getLicenseNotes(): ?string
    {
        return $this->licenseNotes;
    }

    public function setLicenseNotes(?string $licenseNotes): self
    {
        $this->licenseNotes = $licenseNotes;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /** Обгортка для textarea адмінки: один ZIP або префікс на рядок. */
    public function getCoverageZipsText(): string
    {
        return implode("\n", $this->coverageZips ?? []);
    }

    public function setCoverageZipsText(?string $text): self
    {
        $zips = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $text ?? '') ?: []), static fn (string $zip): bool => $zip !== '')));

        return $this->setCoverageZips($zips === [] ? null : $zips);
    }

    public function coversZip(?string $zip): bool
    {
        if ($this->coverageZips === null || $this->coverageZips === []) {
            return true;
        }
        if ($zip === null || $zip === '') {
            return false;
        }
        foreach ($this->coverageZips as $coverage) {
            if ($coverage === $zip || (str_ends_with($coverage, '*') && \strlen($coverage) > 1 && str_starts_with($zip, substr($coverage, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
