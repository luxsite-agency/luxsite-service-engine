<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Validator;

use App\Entity\ServicePostCategory;
use App\ServiceSite\BrandRoutes;
use App\ServiceSite\BrandSettings;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class RootCategorySlugValidator extends ConstraintValidator
{
    public function __construct(
        private readonly BrandSettings $brands,
        private readonly BrandRoutes $brandRoutes,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RootCategorySlug) {
            throw new UnexpectedTypeException($constraint, RootCategorySlug::class);
        }
        $site = $value instanceof ServicePostCategory ? $value->getSite() : null;
        if ($site === null || !$this->brands->categoryRoot($site)) {
            return;
        }
        $reserved = $this->brandRoutes->reservedFor($site);
        $seen = [];
        foreach (['uk' => 'slugUk', 'ru' => 'slugRu', 'en' => 'slugEn'] as $locale => $field) {
            $slug = $value->slug($locale); // ru без свого — slug uk: не повторюємо порушення
            if ($slug !== '' && !isset($seen[$slug]) && \in_array($slug, $reserved, true)) {
                $seen[$slug] = true;
                $this->context->buildViolation($constraint->message)->setParameter('{{ slug }}', $slug)->atPath($field)->addViolation();
            }
        }
    }
}
