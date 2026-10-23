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
        // жива адреса — відмова для будь-якого коду (410/404 теж спрацьовують лише на 404)
        if (($error = $this->redirects->liveMessage($site, $city, $value->getSource())) !== null) {
            $this->context->buildViolation($error)->atPath('source')->addViolation();

            return;
        }
        if (!$value->getCode()->isRedirect()) {
            return;
        }
        if ($value->getTarget() === null) {
            $this->context->buildViolation(trim((string) $value->getRawTarget()) !== '' ? 'Невалідна ціль: шлях від кореня (/…) або повний URL https://…' : RedirectManager::TARGET_REQUIRED)->atPath('target')->addViolation();

            return;
        }
        $target = $this->redirects->localize($site, $value->getTarget());
        if (RedirectPath::isAbsoluteUrl($target) && !$this->security->isGranted('ROLE_ADMIN')) {
            $this->context->buildViolation(RedirectManager::EXTERNAL_DENIED)->atPath('target')->addViolation();

            return;
        }
        try {
            $this->redirects->resolve($site, $city, $value->getSource(), $target);
        } catch (InvalidRedirect $e) {
            $this->context->buildViolation($e->getMessage())->atPath('target')->addViolation();
        }
    }
}
