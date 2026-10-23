<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Validator;

use App\Entity\ServiceTeamMember;
use App\Repository\ServiceTeamMemberRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueTeamSlugValidator extends ConstraintValidator
{
    public function __construct(private readonly ServiceTeamMemberRepository $team)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueTeamSlug) {
            throw new UnexpectedTypeException($constraint, UniqueTeamSlug::class);
        }
        if (!$value instanceof ServiceTeamMember || $value->getCity()?->getSite() === null) {
            return;
        }
        $site = $value->getCity()->getSite();
        foreach (['slugUk' => $value->getSlugUk(), 'slugRu' => $value->getSlugRu()] as $field => $slug) {
            if ($slug === null) {
                continue;
            }
            // uk і ru — один простір адрес: /ru/<префікс>/<слаг>/ без слагу ru бере uk
            $other = $this->team->findOneBySiteAndAnySlug($site, $slug, $value->getId());
            if ($other !== null) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ slug }}', $slug)
                    ->setParameter('{{ name }}', $other->getNameUk())
                    ->atPath($field)
                    ->addViolation();
            }
        }
    }
}
