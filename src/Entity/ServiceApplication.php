<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Анкета — заявка не на послугу (анкета перекладача, резюме, заявка партнера): форма app.applications.<ключ>, окремо від
 * заявок клієнтів. Не йде в CRM угод і не рахується конверсією; лист — на окрему адресу (налаштування сайту
 * forms.applications_email або email форми в паку), у адмінці — розділ «Анкети». Поля форми, крім імені, телефону й email, —
 * у data (порядок і підписи — з конфігурації форми на момент відправки). Файли — ServiceLeadFile, видаляються разом з анкетою.
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_application')]
#[ORM\Index(columns: ['city_id', 'form', 'created_at'], name: 'idx_svc_application_city_form')]
class ServiceApplication implements TimestampableInterface
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ServiceCity $city = null;

    /** Ключ форми з app.applications (translator, partner…) */
    #[ORM\Column(length: 32)]
    private string $form = '';

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $email = null;

    /** @var list<array{key: string, label: string, value: string}> інші поля форми в порядку конфігурації */
    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    /** Людина поставила позначку згоди на обробку персональних даних (обов'язкова, якщо форма її вимагає) */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $consent = false;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $pageUrl = null;

    #[ORM\Column(length: 2, options: ['default' => 'uk'])]
    private string $locale = 'uk';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $processed = false;

    /** @var Collection<int, ServiceLeadFile> */
    #[ORM\OneToMany(targetEntity: ServiceLeadFile::class, mappedBy: 'application', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $files;

    public function __construct()
    {
        $this->files = new ArrayCollection();
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

    public function getForm(): string
    {
        return $this->form;
    }

    public function setForm(string $form): self
    {
        $this->form = $form;

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

    /** @return list<array{key: string, label: string, value: string}> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param list<array{key: string, label: string, value: string}> $data */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /** Значення поля форми за ключем (для шаблонів і CRM паку) */
    public function value(string $key): ?string
    {
        foreach ($this->data as $row) {
            if ($row['key'] === $key) {
                return $row['value'];
            }
        }

        return null;
    }

    public function hasConsent(): bool
    {
        return $this->consent;
    }

    public function getConsent(): bool
    {
        return $this->consent;
    }

    public function setConsent(bool $consent): self
    {
        $this->consent = $consent;

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

    /** @return Collection<int, ServiceLeadFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function getFilesCount(): int
    {
        return $this->files->count();
    }

    public function addFile(ServiceLeadFile $file): self
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setApplication($this);
        }

        return $this;
    }

    public function removeFile(ServiceLeadFile $file): self
    {
        $this->files->removeElement($file);

        return $this;
    }

    public function __toString(): string
    {
        return trim(($this->name ?? '') . ' ' . ($this->phone ?? $this->email ?? ''));
    }
}
