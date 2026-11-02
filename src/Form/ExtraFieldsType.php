<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Form;

use App\ServiceSite\CustomFields;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Додаткові поля запису в адмінці (app.custom_fields паку): по текстовому полю на кожне поле типу запису,
 * для localized — пара uk/ru. Значення — масив {ключ: {uk, ru}} (ExtraFieldsTrait::$extra).
 */
final class ExtraFieldsType extends AbstractType
{
    public function __construct(private readonly CustomFields $fields)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $defs = $this->fields->definitions((string) $options['record_type']);
        foreach ($defs as $def) {
            $scope = $def['categories'] !== [] ? ' (розділи: ' . implode(', ', $def['categories']) . ')' : '';
            $suffix = trim($def['suffix']['uk']);
            $help = ($suffix !== '' ? 'Без одиниці: «' . $suffix . '» допишеться сам.' : '') . ($def['filter'] ? ' Поле для фільтра списку.' : '') . $scope;
            $builder->add($def['key'] . '__uk', TextType::class, [
                'label' => $def['label']['uk'] . ($def['localized'] ? ' (uk)' : ''),
                'required' => false,
                'help' => trim($help) ?: null,
            ]);
            if ($def['localized']) {
                $builder->add($def['key'] . '__ru', TextType::class, [
                    'label' => $def['label']['ru'] . ' (ru)',
                    'required' => false,
                    'help' => 'Порожньо = як uk',
                ]);
            }
        }

        $builder->addModelTransformer(new CallbackTransformer(
            // {key: {uk, ru}} → {key__uk, key__ru}
            static function (?array $extra): array {
                $flat = [];
                foreach ((array) $extra as $key => $langs) {
                    foreach ((array) $langs as $lang => $value) {
                        $flat[$key . '__' . $lang] = (string) $value;
                    }
                }

                return $flat;
            },
            // назад. У масиві лишаються й ключі, яких у формі нема (мапер пише лише в поля-діти): так зберігаються
            // значення полів, прибраних з паку. Але для нелокалізованого поля в формі є лише __uk, а ru з імпорту
            // лишалося б старим і /ru/ показував би його (extraValue бере ru першим) — тому ru таких полів прибираємо.
            static function (?array $flat) use ($defs): ?array {
                $localized = [];
                foreach ($defs as $def) {
                    $localized[$def['key']] = (bool) $def['localized'];
                }
                $extra = [];
                foreach ((array) $flat as $name => $value) {
                    if (!str_contains((string) $name, '__')) {
                        continue;
                    }
                    [$key, $lang] = explode('__', (string) $name, 2);
                    if ($lang !== 'uk' && isset($localized[$key]) && !$localized[$key]) {
                        continue;
                    }
                    $value = trim((string) $value);
                    if ($value !== '') {
                        $extra[$key][$lang] = $value;
                    }
                }

                return $extra === [] ? null : $extra;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'record_type' => 'item', 'required' => false]);
        $resolver->setAllowedValues('record_type', ['item', 'post', 'category', 'page']);
        // EasyAdmin бачить json-поле як колекцію і підмішує свої опції (allow_add,
        // entry_type тощо). Нам вони не потрібні, але без оголошення форма падає
        // з UndefinedOptionsException — сторінка редагування запису віддавала 500.
        $resolver->setDefined(['allow_add', 'allow_delete', 'delete_empty', 'entry_type', 'entry_options']);
    }
}
