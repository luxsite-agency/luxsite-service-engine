<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Doctrine\TimestampableInterface;
use App\Doctrine\TimestampsTrait;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\Index(columns: ['partner_id'], name: 'idx_user_partner')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, \Stringable, TimestampableInterface
{
    use TimestampsTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 190, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\ManyToOne(targetEntity: ServicePartner::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServicePartner $partner = null;

    public function getPartner(): ?ServicePartner
    {
        return $this->partner;
    }

    public function setPartner(?ServicePartner $partner): static
    {
        $this->partner = $partner;

        return $this;
    }

    #[Assert\Callback]
    public function validatePartnerRole(ExecutionContextInterface $context): void
    {
        if (!\in_array('ROLE_PARTNER', $this->roles, true)) {
            return;
        }
        if ($this->partner === null) {
            $context->buildViolation('Для ролі компанії обов’язково оберіть компанію.')->atPath('partner')->addViolation();
        }
        if (array_intersect(['ROLE_MODERATOR', 'ROLE_ADMIN'], $this->roles) !== []) {
            $context->buildViolation('Роль компанії несумісна з ролями модератора й адміністратора.')->atPath('roles')->addViolation();
        }
    }

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** Тимчасове поле форми адмінки (НЕ зберігається): хешується в password при create/update */
    private ?string $plainPassword = null;

    /** Google sub (ID) для входу через Google; null = локальний акаунт. OIDC-спека: sub до 255 символів */
    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $googleId = null;

    /** Публічне ім'я (з Google-профілю) — показується біля відгуків */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $avatarUrl = null;

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): self
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function setAvatarUrl(?string $avatarUrl): self
    {
        $this->avatarUrl = $avatarUrl;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function __toString(): string
    {
        return $this->email;
    }
}
