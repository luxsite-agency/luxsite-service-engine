<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class CatalogNotBlankValidator extends ConstraintValidator
{
    public function __construct(
        #[Autowire('%app.locales%')]
        private readonly array $locales = ['uk', 'ru'],
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CatalogNotBlank) {
            throw new UnexpectedTypeException($constraint, CatalogNotBlank::class);
        }
        $record = $this->context->getObject();
        $property = $this->context->getPropertyName() ?? '';
        $getter = 'get' . ucfirst(substr($property, 0, -2)) . 'En';
        if (!\in_array('uk', $this->locales, true) && \in_array('en', $this->locales, true)
            && str_ends_with($property, 'Uk') && $record !== null && method_exists($record, $getter)
            && trim((string) $record->{$getter}()) !== '') {
            return;
        }

        $this->context->getValidator()->inContext($this->context)->validate($value, new NotBlank(message: $constraint->message));
    }
}
