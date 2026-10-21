<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Entity;

use App\Settings\SecretBox;
use App\Settings\SiteSettingKeys;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Налаштування сайту «ключ → значення» (адмінка → Налаштування, 21.10.2026; модель shop-engine Setting, але на сайт:
 * в одній БД рушія може жити кілька сайтів-брендів). Відомі ключі й типи — SiteSettingKeys. Значення — рядок як є
 * (HTML-коди публікуються сирими, доступ лише ROLE_ADMIN); секрет — лише конверт SecretBox.
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_site_setting')]
#[ORM\UniqueConstraint(name: 'uniq_site_setting_code', columns: ['site_id', 'code'])]
class ServiceSiteSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ServiceSite $site;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(ServiceSite $site, string $code, ?string $value = null)
    {
        $this->site = $site;
        $this->code = SiteSettingKeys::assertKnown($code);
        $this->value = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ServiceSite
    {
        return $this->site;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    /** Порожній рядок → null; id-поля нормалізуються (вставлений сніпет → id), HTML і конверти секретів — як є. */
    public function setValue(?string $value): self
    {
        $this->value = SiteSettingKeys::isSecret($this->code)
            ? (null === $value || '' === trim($value) ? null : $value)
            : SiteSettingKeys::normalize($this->code, $value);
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Значення для показу: секрет — лише маска (шаблони полів EasyAdmin кладуть сире значення в title). */
    public function getValueText(): ?string
    {
        return SiteSettingKeys::isSecret($this->code) ? SecretBox::mask($this->value) : $this->value;
    }

    /** Формат id перевіряється при збереженні: невалідне значення не зберігається (помилка біля поля). */
    #[Assert\Callback]
    public function validateFormat(ExecutionContextInterface $context): void
    {
        if (SiteSettingKeys::isSecret($this->code)) {
            return;
        }
        if (!SiteSettingKeys::isValidValue($this->code, $this->value)) {
            $context->buildViolation('setting.invalid_format')
                ->setTranslationDomain('admin')
                ->atPath('value')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
