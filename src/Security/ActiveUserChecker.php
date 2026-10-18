<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Деактивація в адмінці (User.active) — єдиний механізм відкликання доступу
 * (DELETE вимкнено в UserCrud). Без цього чекера вимкнений користувач
 * логінився б далі, а remember_me тримав би сесію ще 2 тижні.
 */
final class ActiveUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isActive()) {
            throw new DisabledException('Обліковий запис деактивовано.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // remember_me/сесії проходять через checkPreAuth — додаткових перевірок немає
    }
}
