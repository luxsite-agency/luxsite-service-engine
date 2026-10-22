<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\AdminApi\ApiScope;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Персональний API-токен менеджера (15.10.2026, модель shop-engine): у БД лише sha256-хеш, у prefix — перші 12 символів
 * (`svc_` + 8) для впізнавання. Значення показується один раз при створенні. Строк ≤ app.admin_api_token_max_days;
 * продовження = новий токен; відкликаний чи прострочений не оживає. Деактивація користувача гасить усі його токени.
 */
#[ORM\Entity]
#[ORM\Table(name: 'admin_api_token')]
#[ORM\UniqueConstraint(name: 'uniq_admin_api_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_admin_api_token_user', columns: ['user_id', 'created_at'])]
class AdminApiToken
{
    public const int PREFIX_LENGTH = 12;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $tokenHash;

    #[ORM\Column(length: 16)]
    private string $prefix;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** Хто відкликав (id користувача, без FK: рядок переживає видалення). */
    #[ORM\Column(nullable: true)]
    private ?int $revokedById = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /** Анонімізований (IpUtils::anonymize). */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $lastUsedIp = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Бренди токена (slug-и; 22.10.2026): null — усі бренди власника. Видача перевіряє, що це підмножина User.sites.
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $sites = null;

    /** @param list<ApiScope> $scopes */
    public function __construct(User $user, string $name, array $scopes, string $tokenHash, string $prefix, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->name = $name;
        $this->scopes = array_values(array_unique(array_map(static fn (ApiScope $s): string => $s->value, $scopes)));
        $this->tokenHash = $tokenHash;
        $this->prefix = $prefix;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getOwnerName(): string
    {
        return $this->user->getDisplayName() ?: $this->user->getEmail();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /** Як показувати в списках: `svc_a1b2c3d4…`. */
    public function getMaskedToken(): string
    {
        return $this->prefix . '…';
    }

    /** @return list<ApiScope> */
    public function getScopes(): array
    {
        $out = [];
        foreach ($this->scopes as $value) {
            $scope = ApiScope::tryFrom($value);
            if ($scope !== null) {
                $out[] = $scope;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function getScopeValues(): array
    {
        return $this->scopes;
    }

    /** @return list<string>|null */
    public function getSites(): ?array
    {
        return $this->sites;
    }

    /** @param list<string>|null $sites */
    public function setSites(?array $sites): self
    {
        $sites = $sites === null ? [] : array_values(array_unique(array_filter($sites, 'is_string')));
        $this->sites = $sites === [] ? null : $sites;

        return $this;
    }

    /** Токен працює з брендом: бренд у списку токена (або список порожній) і власник має до нього доступ */
    public function allowsSite(ServiceSite $site): bool
    {
        return $this->user->canAccessSite($site) && ($this->sites === null || \in_array($site->getSlug(), $this->sites, true));
    }

    public function hasScope(ApiScope $scope): bool
    {
        return \in_array($scope->value, $this->scopes, true);
    }

    public function getScopesText(): string
    {
        return implode(', ', $this->scopes);
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevokedById(): ?int
    {
        return $this->revokedById;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastUsedIp(): ?string
    {
        return $this->lastUsedIp;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /** Чинний: не відкликаний і не прострочений (активність власника перевіряє user_checker). */
    public function isUsable(?\DateTimeImmutable $now = null): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    public function getStatusLabel(): string
    {
        if ($this->isRevoked()) {
            return 'відкликано';
        }
        if ($this->isExpired()) {
            return 'прострочено';
        }

        return 'чинний';
    }

    public function revoke(?User $by, ?\DateTimeImmutable $at = null): void
    {
        if ($this->isRevoked()) {
            return;
        }
        $this->revokedAt = $at ?? new \DateTimeImmutable();
        $this->revokedById = $by?->getId();
    }

    public function touch(\DateTimeImmutable $at, ?string $anonymizedIp): void
    {
        $this->lastUsedAt = $at;
        $this->lastUsedIp = $anonymizedIp;
    }
}
