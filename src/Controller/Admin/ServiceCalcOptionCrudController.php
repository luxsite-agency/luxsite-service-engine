<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCalcOption;
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
class ServiceCalcOptionCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: назва опції, а не сайт */
    private const INDEX_PRIMARY = 'nameUk';

    public static function getEntityFqcn(): string
    {
        return ServiceCalcOption::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Додаткова опція')
            ->setEntityLabelInPlural('Калькулятор: додаткові опції')
            ->setDefaultSort(['site' => 'ASC', 'position' => 'ASC'])
            ->setSearchFields(['nameUk', 'nameRu'])
            ->setPaginatorPageSize(60)
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('site', 'Сайт'))
            ->add(BooleanFilter::new('perUnit', 'За одиницю'))
            ->add(BooleanFilter::new('active', 'Активна'));
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
        yield AssociationField::new('site', 'Сайт')->setColumns(6)
            ->setHelp(
                '⚠️ Розділ застарілий: ціни тепер ведуться в «Прайсі», з галочкою «У калькуляторі». Ця '
                . 'таблиця лишається запасним варіантом.'
            );
        yield FormField::addFieldset('Назва');
        yield TextField::new('nameUk', 'Назва uk')->setColumns(6);
        yield TextField::new('nameRu', 'Назва ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Ціна');
        yield NumberField::new('price', 'Ціна, грн')->setNumDecimals(2)->setColumns(4)
            ->setHelp('Ціна позиції в грн.');
        yield BooleanField::new('perUnit', 'Ціна за одиницю')->setColumns(4)
            ->setHelp('Увімкнено = степер кількості (вікна, посадкові місця); вимкнено = чекбокс із фіксованою ціною');
        yield IntegerField::new('maxQty', 'Макс. кількість')->hideOnIndex()->setColumns(4)
            ->setHelp('Верхня межа степера, діє лише для ціни за одиницю');
        yield FormField::addRow();
        yield TextField::new('unitUk', 'Одиниця uk')->hideOnIndex()->setColumns(6)
            ->setHelp('«стулка», «посадкове місце», «м²» - показується біля степера');
        yield TextField::new('unitRu', 'Одиниця ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Налаштування');
        yield IntegerField::new('position', 'Порядок')->setColumns(6)
            ->setHelp('Порядок у списку додаткових послуг.');
        yield BooleanField::new('active', 'Активна')->setColumns(6)
            ->setHelp('Вимкнена опція зникає з калькулятора.');
    }
}
