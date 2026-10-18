<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Створити користувача адмінки')]
class UserCreateCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email користувача')] string $email,
        #[Argument('Пароль')] string $password,
        #[Option('Роль: admin | moderator | editor | business | user')] string $role = 'admin',
    ): int {
        if (null !== $this->users->findOneBy(['email' => $email])) {
            $io->error(sprintf('Користувач %s вже існує.', $email));

            return 1;
        }

        $roles = match ($role) {
            'admin' => ['ROLE_ADMIN'],
            'moderator' => ['ROLE_MODERATOR'],
            'editor' => ['ROLE_EDITOR'],
            'business' => ['ROLE_BUSINESS'],
            'user' => ['ROLE_USER'],
            default => null,
        };
        if (null === $roles) {
            $io->error('Невідома роль: ' . $role);

            return 1;
        }

        $user = (new User())->setEmail($email)->setRoles($roles);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Користувача %s (%s) створено.', $email, $role));

        return 0;
    }
}
