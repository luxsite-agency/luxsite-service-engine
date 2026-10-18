<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCalcFactor;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
class ServiceCalcFactorCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: назва варіанта, а не сайт із групою */
    private const INDEX_PRIMARY = 'nameUk';

    /** Підписи груп в адмінці (на сайті - translations calc.group.*) */
    private const GROUP_LABELS = [
        'Тип обʼєкта' => 'object_type',
        'Стан забруднення' => 'dirt_level',
        'Терміновість' => 'urgency',
        'Поверх і ліфт' => 'floor',
        'День тижня' => 'weekend',
    ];

    public static function getEntityFqcn(): string
    {
        return ServiceCalcFactor::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Коефіцієнт')
            ->setEntityLabelInPlural('Калькулятор: коефіцієнти')
            ->setDefaultSort(['site' => 'ASC', 'groupCode' => 'ASC', 'position' => 'ASC'])
            ->setSearchFields(['nameUk', 'nameRu'])
            ->setPaginatorPageSize(60)
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('site', 'Сайт'))
            ->add(ChoiceFilter::new('groupCode', 'Група')->setChoices(self::GROUP_LABELS))
            ->add(BooleanFilter::new('active', 'Активний'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        // підказки в шапці таблиці беруться з setHelp() самих полів
        yield from $this->withHints($this->primaryFirst($this->fieldList($pageName), $pageName));
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('site', 'Сайт')->setColumns(6);
        yield ChoiceField::new('groupCode', 'Група')
            ->setChoices(self::GROUP_LABELS)->setColumns(6)
            ->setHelp('У калькуляторі кожна група - окремий ряд варіантів, обирається один')
            ->setHtmlAttributes($this->hint(
                'Питання в калькуляторі: тип обʼєкта, забруднення, поверх, терміновість. Усередині '
                . 'групи людина обирає рівно один варіант.'
            ));
        yield FormField::addFieldset('Варіант');
        yield TextField::new('nameUk', 'Назва uk')->setColumns(6)
            ->setHtmlAttributes($this->hint('Текст кнопки в калькуляторі українською.'));
        yield TextField::new('nameRu', 'Назва ru')->hideOnIndex()->setColumns(6);
        yield NumberField::new('multiplier', 'Множник')
            ->setNumDecimals(2)->setColumns(4)
            ->setHelp('1.00 = без надбавки, 1.25 = +25% до вартості')
            ->setHtmlAttributes($this->hint(
                'На скільки цей варіант змінює суму. 1.00 - без змін, 1.25 - дорожче на 25%, 0.90 - '
                . 'дешевше на 10%. Множники обраних варіантів перемножуються.'
            ));
        yield BooleanField::new('isDefault', 'Передобраний')->setColumns(4)
            ->setHelp('Варіант, обраний при відкритті калькулятора (один на групу)')
            ->setHtmlAttributes($this->hint(
                'Варіант, який стоїть одразу при відкритті калькулятора. У кожній групі має бути '
                . 'рівно один.'
            ));
        yield FormField::addFieldset('Налаштування');
        yield IntegerField::new('position', 'Порядок')->setColumns(6)
            ->setHtmlAttributes($this->hint(
                'Чим менше число, тим вище варіант у списку. Крок 10 зручний: між 10 і 20 можна '
                . 'вставити новий, не перенумеровуючи решту.'
            ));
        yield BooleanField::new('active', 'Активний')
            ->setColumns(6)
            ->setHtmlAttributes($this->hint('Вимкнений варіант зникає з калькулятора, але лишається тут і в старих заявках.'));
    }
}
