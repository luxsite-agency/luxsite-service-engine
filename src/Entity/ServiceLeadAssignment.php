<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServiceLeadAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ServiceLeadAssignmentRepository::class)]
#[ORM\Table(name: 'service_lead_assignment')]
#[ORM\UniqueConstraint(name: 'uniq_svc_assignment_lead_partner', columns: ['lead_id', 'partner_id'])]
#[ORM\Index(columns: ['partner_id', 'assigned_at'], name: 'idx_svc_assignment_partner_assigned')]
#[ORM\Index(columns: ['next_attempt_at'], name: 'idx_svc_assignment_next_attempt')]
class ServiceLeadAssignment
{
    public const string STATUS_NEW = 'new';
    public const string STATUS_ACCEPTED = 'accepted';
    public const string STATUS_NO_ANSWER = 'no_answer';
    public const string STATUS_JUNK = 'junk';
    public const string STATUS_WON = 'won';
    public const string STATUS_LOST = 'lost';
    public const array STATUSES = [self::STATUS_NEW, self::STATUS_ACCEPTED, self::STATUS_NO_ANSWER, self::STATUS_JUNK, self::STATUS_WON, self::STATUS_LOST];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceLead::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceLead $lead = null;

    #[ORM\ManyToOne(targetEntity: ServicePartner::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?ServicePartner $partner = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $assignedAt;

    /** Час успішної доставки email партнеру. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $webhookDeliveredAt = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 16, options: ['default' => 'new'])]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $statusAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $statusNote = null;

    #[ORM\Column(nullable: true)]
    private ?int $price = null;

    public function __construct()
    {
        $this->assignedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLead(): ?ServiceLead
    {
        return $this->lead;
    }

    public function setLead(ServiceLead $lead): self
    {
        $this->lead = $lead;

        return $this;
    }

    public function getPartner(): ?ServicePartner
    {
        return $this->partner;
    }

    public function setPartner(ServicePartner $partner): self
    {
        $this->partner = $partner;

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

    public function getAssignedAt(): \DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function setAssignedAt(\DateTimeImmutable $assignedAt): self
    {
        $this->assignedAt = $assignedAt;

        return $this;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;

        return $this;
    }

    public function getWebhookDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->webhookDeliveredAt;
    }

    public function setWebhookDeliveredAt(?\DateTimeImmutable $at): self
    {
        $this->webhookDeliveredAt = $at;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): self
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function setNextAttemptAt(?\DateTimeImmutable $at): self
    {
        $this->nextAttemptAt = $at;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $error): self
    {
        $this->lastError = $error === null ? null : mb_substr($error, 0, 500);

        return $this;
    }

    /** @return list<'email'|'webhook'> */
    public function pendingChannels(): array
    {
        $pending = [];
        if (trim($this->partner?->getNotifyEmails() ?? '') !== '' && $this->deliveredAt === null) {
            $pending[] = 'email';
        }
        if (trim($this->partner?->getWebhookUrl() ?? '') !== '' && $this->webhookDeliveredAt === null) {
            $pending[] = 'webhook';
        }

        return $pending;
    }

    public function isFullyDelivered(): bool
    {
        return $this->pendingChannels() === [];
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

    public function getStatusAt(): ?\DateTimeImmutable
    {
        return $this->statusAt;
    }

    public function setStatusAt(?\DateTimeImmutable $statusAt): self
    {
        $this->statusAt = $statusAt;

        return $this;
    }

    public function getStatusNote(): ?string
    {
        return $this->statusNote;
    }

    public function setStatusNote(?string $statusNote): self
    {
        $this->statusNote = $statusNote;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function __toString(): string
    {
        return sprintf('#%s → %s', $this->lead?->getId(), $this->partner?->getName());
    }
}
