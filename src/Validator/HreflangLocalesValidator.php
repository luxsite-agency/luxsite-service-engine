<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Validator;

use App\Entity\ServiceSiteSetting;
use App\Settings\SiteSettingKeys;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class HreflangLocalesValidator extends ConstraintValidator
{
    /** @param list<string> $locales app.locales */
    public function __construct(
        #[Autowire('%app.locales%')]
        private readonly array $locales = [],
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof HreflangLocales) {
            throw new UnexpectedTypeException($constraint, HreflangLocales::class);
        }
        if (!$value instanceof ServiceSiteSetting || SiteSettingKeys::HREFLANG_CODES !== $value->getCode()) {
            return;
        }
        // формат не той — про це вже скаже validateFormat сутності; тут лише мови
        $unknown = array_diff(array_keys(SiteSettingKeys::parseHreflangCodes($value->getValue()) ?? []), $this->locales);
        if ([] !== $unknown) {
            $this->context->buildViolation($constraint->message)
                ->setTranslationDomain('admin')
                ->setParameter('%locales%', implode(', ', $unknown))
                ->setParameter('%allowed%', implode(', ', $this->locales))
                ->atPath('value')
                ->addViolation();
        }
    }
}
