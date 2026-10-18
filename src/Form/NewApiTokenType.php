<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Form;

use App\AdminApi\ApiScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Форма «Створити API-токен»: назва, дозволи (лише доступні ролі власника — опція scopes), строк у днях ≤ max_days.
 * Дані — масив {name, scopes: list<ApiScope>, days}.
 *
 * @extends AbstractType<array{name: string, scopes: list<ApiScope>, days: int}>
 */
final class NewApiTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<ApiScope> $scopes */
        $scopes = $options['scopes'];
        /** @var int $maxDays */
        $maxDays = $options['max_days'];
        $builder
            ->add('name', TextType::class, [
                'label' => 'Назва',
                'help' => 'Для чого токен: «Claude Code на ноутбуці», «content-studio». Видно лише вам і адміністратору.',
                'constraints' => [new NotBlank(message: 'Вкажіть назву'), new Length(max: 100, maxMessage: 'Не довше за 100 символів')],
            ])
            ->add('scopes', ChoiceType::class, [
                'label' => 'Дозволи',
                'choices' => $scopes,
                'choice_label' => static fn (ApiScope $s): string => sprintf('%s — %s', $s->value, $s->label()),
                'choice_value' => static fn (?ApiScope $s): string => $s === null ? '' : $s->value,
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Count(min: 1, minMessage: 'Виберіть хоча б один дозвіл')],
            ])
            ->add('days', IntegerType::class, [
                'label' => 'Строк дії, днів',
                'data' => $maxDays,
                'help' => sprintf('Не довше за %d днів. Продовження — новий токен, старий відкликати.', $maxDays),
                'constraints' => [new NotBlank(message: 'Вкажіть строк'), new Range(min: 1, max: $maxDays, notInRangeMessage: 'Від {{ min }} до {{ max }} днів')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['scopes', 'max_days'])
            ->setAllowedTypes('scopes', 'array')
            ->setAllowedTypes('max_days', 'int')
            ->setDefault('csrf_token_id', 'api-token-issue');
    }
}
