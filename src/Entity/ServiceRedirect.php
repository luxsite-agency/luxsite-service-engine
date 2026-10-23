<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServiceRedirectRepository;
use App\ServiceSite\Redirect\RedirectCode;
use App\ServiceSite\Redirect\RedirectKind;
use App\ServiceSite\Redirect\RedirectPath;
use App\ServiceSite\Redirect\ValidRedirect;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Правило менеджера редиректів (модель shop-engine E3, 23.10.2026): адреса сайту, якої вже нема, → 301/302 на нову або
 * 404/410. Спрацьовує лише на 404 сайту (NotFoundRedirectSubscriber) — живі сторінки правило не перекриває; правила паку
 * (app.legacy_redirects, LegacyUrlSubscriber до роутингу) мають перевагу. Правило належить сайту-бренду; місто задане —
 * діє лише на його хості, порожнє — на всіх хостах сайту (у пошуку правило міста має перевагу). Джерело нормалізоване
 * (RedirectPath), унікальне в межах сайту й міста, у бінарному порівнянні. Ланцюгів нема: ціль 301/302 ніколи не є
 * джерелом іншого 301/302 у тій самій області (RedirectManager).
 */
#[ORM\Entity(repositoryClass: ServiceRedirectRepository::class)]
#[ORM\Table(name: 'service_redirect')]
#[ORM\UniqueConstraint(name: 'uniq_service_redirect_source', columns: ['site_id', 'city_key', 'source'])]
#[ORM\Index(name: 'idx_service_redirect_target_path', columns: ['site_id', 'target_path'])]
#[UniqueEntity(fields: ['site', 'cityKey', 'source'], message: 'Для цієї адреси (і цього міста) правило вже є', errorPath: 'source')]
#[ValidRedirect]
class ServiceRedirect
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Оберіть сайт')]
    private ?ServiceSite $site = null;

    /** Місто (хост), на якому діє правило; null — усі міста сайту. */
    #[ORM\ManyToOne(targetEntity: ServiceCity::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ServiceCity $city = null;

    /**
     * id міста або 0 для правила на всі міста — для унікального індексу (site, city_key, source): NULL у MySQL унікальність
     * не тримає, і сайт отримав би два правила «на всі міста» з одним джерелом. Задається разом із city.
     */
    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $cityKey = 0;

    #[ORM\Column(length: RedirectPath::MAX_LENGTH, options: ['collation' => 'utf8mb4_bin'])]
    #[Assert\NotBlank(message: 'Вкажіть адресу, з якої переадресувати (шлях від кореня сайту)')]
    private string $source = '';

    #[ORM\Column(length: RedirectPath::MAX_TARGET_LENGTH, nullable: true)]
    private ?string $target = null;

    /** Ціль-шлях без query (RedirectPath::targetPath) — індекс для злиття ланцюгів «X→A, тепер A→B ⇒ X→B». */
    #[ORM\Column(length: RedirectPath::MAX_LENGTH, nullable: true, options: ['collation' => 'utf8mb4_bin'])]
    private ?string $targetPath = null;

    #[ORM\Column(type: Types::SMALLINT, enumType: RedirectCode::class)]
    private RedirectCode $code = RedirectCode::Permanent;

    #[ORM\Column(length: 8, enumType: RedirectKind::class)]
    private RedirectKind $kind = RedirectKind::Manual;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $hits = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastHitAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Сирі значення з форми (до нормалізації) — для повідомлень валідатора. */
    private ?string $rawSource = null;
    private ?string $rawTarget = null;

    public function __construct(?ServiceSite $site = null, string $source = '', RedirectCode $code = RedirectCode::Permanent, ?string $target = null, ?ServiceCity $city = null)
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->site = $site;
        $this->setCity($city);
        $this->setSource($source);
        $this->setCode($code);
        $this->setTarget($target);
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

    public function getCity(): ?ServiceCity
    {
        return $this->city;
    }

    public function setCity(?ServiceCity $city): self
    {
        $this->city = $city;
        $this->cityKey = (int) ($city?->getId() ?? 0);

        return $this;
    }

    public function getCityKey(): int
    {
        return $this->cityKey;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->rawSource = $source;
        $this->source = RedirectPath::normalize((string) $source) ?? '';

        return $this;
    }

    public function getRawSource(): ?string
    {
        return $this->rawSource;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    /** Для 404/410 ціль не зберігається. Невалідна ціль → null (валідатор повідомить за rawTarget). */
    public function setTarget(?string $target): self
    {
        $this->rawTarget = $target;
        $this->target = $target === null || trim($target) === '' ? null : RedirectPath::normalizeTarget($target);
        $this->targetPath = $this->target === null ? null : RedirectPath::targetPath($this->target);

        return $this;
    }

    public function getRawTarget(): ?string
    {
        return $this->rawTarget;
    }

    public function getTargetPath(): ?string
    {
        return $this->targetPath;
    }

    public function getCode(): RedirectCode
    {
        return $this->code;
    }

    public function setCode(RedirectCode $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getKind(): RedirectKind
    {
        return $this->kind;
    }

    public function setKind(RedirectKind $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function getLastHitAt(): ?\DateTimeImmutable
    {
        return $this->lastHitAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Ціль після злиття ланцюга (RedirectManager::resolve), без сирого значення форми. */
    public function retarget(string $target): void
    {
        $this->target = $target;
        $this->targetPath = RedirectPath::targetPath($target);
    }

    /** 404/410 ціль не мають. */
    public function dropTarget(): void
    {
        $this->target = $this->targetPath = null;
    }
}
