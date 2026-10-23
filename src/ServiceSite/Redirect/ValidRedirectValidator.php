<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

use App\Entity\ServiceRedirect;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidRedirectValidator extends ConstraintValidator
{
    public function __construct(private readonly RedirectManager $redirects, private readonly Security $security)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRedirect) {
            throw new UnexpectedTypeException($constraint, ValidRedirect::class);
        }
        if (!$value instanceof ServiceRedirect) {
            return;
        }
        $site = $value->getSite();
        if ($site === null) {
            return; // NotNull на site
        }
        $city = $value->getCity();
        if ($city !== null && $city->getSite()?->getId() !== $site->getId()) {
            $this->context->buildViolation('Місто належить іншому сайту')->atPath('city')->addViolation();

            return;
        }
        if ($value->getSource() === '') {
            if (trim((string) $value->getRawSource()) !== '') {
                $this->context->buildViolation('Невалідна адреса: потрібен шлях від кореня сайту (/stara-adresa/) або повний URL старої сторінки')->atPath('source')->addViolation();
            }

            return;
        }
        $code = $value->getCode();
        if (!$code->isRedirect()) {
            return;
        }
        if ($value->getTarget() === null && trim((string) $value->getRawTarget()) !== '') {
            $this->context->buildViolation('Невалідна ціль: шлях від кореня (/…) або повний URL https://…')->atPath('target')->addViolation();

            return;
        }
        $target = $value->getTarget() === null ? null : $this->redirects->localize($site, $value->getTarget());
        if ($target !== null && RedirectPath::isAbsoluteUrl($target) && !$this->security->isGranted('ROLE_ADMIN')) {
            $this->context->buildViolation(RedirectManager::EXTERNAL_DENIED)->atPath('target')->addViolation();

            return;
        }
        if (($error = $this->redirects->check($site, $city, $value->getSource(), $code, $target)) !== null) {
            $this->context->buildViolation($error)->atPath('target')->addViolation();
        }
    }
}
