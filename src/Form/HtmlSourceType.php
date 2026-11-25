<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * HTML-код поля як є (textarea, без візуального редактора й без trim).
 *
 * Захист від тихого перезапису: якщо надіслане значення після нормалізації (переноси рядків, пробіли по краях —
 * те, що міняє браузер або textarea, а не людина) збігається зі збереженим, у запис іде збережене байт-у-байт.
 * Тож збереження форми через інше поле не чіпає HTML полів, яких не редагували.
 */
final class HtmlSourceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();
            $stored = $event->getForm()->getData();
            if (!\is_string($submitted) || self::normalize($submitted) !== self::normalize($stored ?? '')) {
                return;
            }
            $event->setData($stored ?? '');
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['trim' => false]);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    private static function normalize(mixed $value): string
    {
        return \is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';
    }
}
