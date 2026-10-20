<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Перевірка слага в адмінці: чи не займе запис адресу в корені сайту, де вже є інший запис (послуга, категорія,
 * сторінка) або фіксований розділ (App\ServiceSite\RootSlugs::conflictsFor). Діє лише там, де запис за схемою
 * живе в корені (категорія — root/slug, послуга — slug, сторінка — app.pages); інакше нічого не перевіряє.
 * Контролер має властивість $rootSlugs (App\ServiceSite\RootSlugs).
 */
trait RootSlugConstraintsTrait
{
    /**
     * Обмеження для поля slugUk ('uk') чи slugRu ('ru'). Запис береться з кореня форми: на момент валідації
     * поля форми вже записані в нього, тож порожній slugRu перевіряється як uk-слаг на /ru/… (так генерується адреса).
     *
     * @return list<Callback>
     */
    private function rootSlugConstraints(string $locale): array
    {
        return [new Callback(function (mixed $value, ExecutionContextInterface $context) use ($locale): void {
            $form = $context->getRoot();
            $record = $form instanceof FormInterface ? $form->getData() : null;
            if (!\is_object($record)) {
                return;
            }
            foreach ($this->rootSlugs->conflictsFor($record, $locale) as $message) {
                $context->buildViolation($message)->addViolation();
            }
        })];
    }
}
