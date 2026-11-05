<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Security;

use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\Entity\User;
use App\ServiceSite\Partners\PartnersConfig;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Єдина перевірка доступу для всіх сторінок, CSV та CRUD кабінету. */
final class PartnerCabinetAccess
{
    public function __construct(private readonly PartnersConfig $config, private readonly Security $security)
    {
    }

    public function partner(): ServicePartner
    {
        if (!$this->config->enabled) {
            throw new NotFoundHttpException();
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isActive() || !$this->security->isGranted('ROLE_PARTNER')
            || $this->security->isGranted('ROLE_MODERATOR') || $user->getPartner() === null
            || $user->getPartner()->getStatus() === ServicePartner::STATUS_LEFT) {
            throw new AccessDeniedException();
        }

        return $user->getPartner();
    }

    public function assignment(mixed $assignment): ServiceLeadAssignment
    {
        $partner = $this->partner();
        if (!$assignment instanceof ServiceLeadAssignment || $assignment->getPartner()?->getId() !== $partner->getId()) {
            throw new NotFoundHttpException();
        }

        return $assignment;
    }
}
