<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Файл, доданий до заявки (фото чи PDF документа; app.lead_files). Лежить поза public/ — у приватній теці
 * App\ServiceSite\LeadFiles (на проді shared/lead-files), віддається лише модератору адмінки (/admin/lead-file/<id>).
 * Видаляється разом із заявкою (ORM: файл на диску — LeadFiles::postRemove) і за терміном зберігання (app:lead-files:prune).
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_lead_file')]
#[ORM\Index(columns: ['created_at'], name: 'idx_svc_lead_file_created')]
class ServiceLeadFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceLead::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ServiceLead $lead = null;

    /** Ім'я файлу, як його надіслала людина (для підпису й завантаження); у шлях на диску не потрапляє */
    #[ORM\Column(length: 255)]
    private string $originalName = '';

    /** Ім'я на диску: випадкові 32 hex + розширення за MIME */
    #[ORM\Column(length: 64, unique: true)]
    private string $storedName = '';

    #[ORM\Column(length: 100)]
    private string $mimeType = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $size = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLead(): ?ServiceLead
    {
        return $this->lead;
    }

    public function setLead(?ServiceLead $lead): self
    {
        $this->lead = $lead;

        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): self
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getStoredName(): string
    {
        return $this->storedName;
    }

    public function setStoredName(string $storedName): self
    {
        $this->storedName = $storedName;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Розмір для людини: «2,4 МБ», «320 КБ» */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? str_replace('.', ',', sprintf('%.1f МБ', $this->size / 1048576))
            : sprintf('%d КБ', max(1, (int) round($this->size / 1024)));
    }

    public function __toString(): string
    {
        return $this->originalName . ' (' . $this->sizeLabel() . ')';
    }
}
