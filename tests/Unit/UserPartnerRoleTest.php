<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServicePartner;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class UserPartnerRoleTest extends TestCase
{
    public static function roles(): iterable
    {
        yield 'partner needs company' => [['ROLE_PARTNER'], false, ['partner']];
        yield 'partner with company' => [['ROLE_PARTNER'], true, []];
        yield 'moderator conflict' => [['ROLE_PARTNER', 'ROLE_MODERATOR'], true, ['roles']];
        yield 'admin conflict' => [['ROLE_PARTNER', 'ROLE_ADMIN'], true, ['roles']];
        yield 'both errors' => [['ROLE_PARTNER', 'ROLE_MODERATOR'], false, ['partner', 'roles']];
        yield 'ordinary user' => [['ROLE_USER'], false, []];
        yield 'moderator' => [['ROLE_MODERATOR'], false, []];
    }

    #[DataProvider('roles')]
    public function testPartnerRoleValidation(array $roles, bool $hasPartner, array $paths): void
    {
        $user = (new User())->setEmail('cabinet@example.test')->setRoles($roles);
        if ($hasPartner) {
            $user->setPartner(new ServicePartner());
        }
        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($user);
        self::assertSame($paths, array_map(static fn ($v): string => $v->getPropertyPath(), iterator_to_array($violations)));
    }
}
