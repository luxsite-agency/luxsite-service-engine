<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Один пункт FAQ {q, a} — entry type для CollectionField
 * (ServiceCity.faqUk/faqRu, ServiceItem.faqUk/faqRu; JSON-колонки).
 */
final class ServiceFaqItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', TextType::class, ['label' => 'Питання'])
            ->add('a', TextareaType::class, ['label' => 'Відповідь', 'attr' => ['rows' => 3]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // data_class немає — працюємо з масивами {q, a} у JSON-колонці
        $resolver->setDefaults(['data_class' => null]);
    }
}
