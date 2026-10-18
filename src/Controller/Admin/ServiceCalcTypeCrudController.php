<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCalcType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
class ServiceCalcTypeCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: назва типу, а не сайт */
    private const INDEX_PRIMARY = 'nameUk';

    public static function getEntityFqcn(): string
    {
        return ServiceCalcType::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Тип прибирання')
            ->setEntityLabelInPlural('Калькулятор: типи прибирання')
            ->setDefaultSort(['site' => 'ASC', 'position' => 'ASC'])
            ->setHelp('index', 'Базові ставки калькулятора: діють у всіх містах одразу.
                Якщо в якомусь місті ціна інша - не міняйте тут, а заведіть рядок
                у розділі «Ціни по містах», інакше зміна поїде на всі чотири сайти.')
            ->setHelp('edit', 'Зміна тут торкнеться всіх міст, де немає власного рядка
                в «Цінах по містах».')
            ->setSearchFields(['nameUk', 'nameRu'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('site', 'Сайт'))
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
        yield FormField::addFieldset('Назва і підпис');
        yield TextField::new('nameUk', 'Назва uk')->setColumns(6)
            ->setHtmlAttributes($this->hint('Назва кнопки вибору в калькуляторі: «Генеральне прибирання».'));
        yield TextField::new('nameRu', 'Назва ru')->hideOnIndex()->setColumns(6);
        yield TextField::new('descUk', 'Підпис uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Короткий склад робіт під назвою («пил, підлога, санвузол, кухня»)');
        yield TextField::new('descRu', 'Підпис ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Ціна');
        yield NumberField::new('pricePerSqm', 'Базова ціна за м², грн')
            ->setNumDecimals(2)->setColumns(6)
            ->setHelp('Діє в усіх містах, крім тих, для яких є рядок у «Цінах по містах»')
            ->setHtmlAttributes($this->hint(
                'Скільки коштує один квадратний метр. Діє в усіх містах, крім тих, для яких заведено '
                . 'рядок у розділі «Типи: ціни по містах».'
            ));
        yield NumberField::new('minPrice', 'Мінімальний чек, грн')->setNumDecimals(2)->setColumns(6)
            ->setHelp('Підсумок не опускається нижче цієї суми, навіть на малій площі')
            ->setHtmlAttributes($this->hint(
                'Нижче цієї суми розрахунок не опускається, якою б малою не була площа. Це вартість '
                . 'виїзду бригади.'
            ));
        yield FormField::addFieldset('Налаштування');
        yield IntegerField::new('position', 'Порядок')->setColumns(6)
            ->setHtmlAttributes($this->hint('Чим менше число, тим лівіше кнопка типу в калькуляторі.'));
        yield BooleanField::new('active', 'Активний')->setColumns(6)
            ->setHtmlAttributes($this->hint('Вимкнений тип зникає з калькулятора на всіх містах.'));
    }
}
