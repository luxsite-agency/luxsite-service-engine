<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServicePrice;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
class ServicePriceCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: назва позиції, а не послуга з містом */
    private const INDEX_PRIMARY = 'nameUk';

    public static function getEntityFqcn(): string
    {
        return ServicePrice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Позиція прайсу')
            ->setEntityLabelInPlural('Прайс')
            ->setDefaultSort(['city' => 'ASC', 'item' => 'ASC', 'position' => 'ASC'])
            ->setSearchFields(['nameUk', 'nameRu'])
            ->setPaginatorPageSize(60)
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('city', 'Місто'))
            ->add(EntityFilter::new('item', 'Послуга'))
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
        // форма коротка - таби не потрібні, лише колонки (вердикт консиліуму)
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('item', 'Послуга')->setColumns(6)
            ->setRequired(false)
            ->setHelp('Порожньо - позиція загального прайсу, показується лише в калькуляторі')
            ->setHtmlAttributes($this->hint(
                'На сторінці якої послуги показувати рядок. Порожньо - позиція загального прайсу: у '
                . 'таблицях її не буде, лише в калькуляторі.'
            ));
        yield AssociationField::new('city', 'Місто')->setColumns(6)
            ->setHtmlAttributes($this->hint(
                'Ціни в містах різні, тому кожна позиція заводиться окремо на кожне місто. Рядок '
                . 'бачать лише відвідувачі цього міського сайту.'
            ));
        yield FormField::addFieldset('Назва і опис');
        yield TextField::new('nameUk', 'Назва uk')->setColumns(6)
            ->setHtmlAttributes($this->hint('Перша колонка таблиці на сторінці: «Диван на 3 місця», «51-75 м²».'));
        yield TextField::new('nameRu', 'Назва ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('descUk', 'Опис uk')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('descRu', 'Опис ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Ціна');
        yield NumberField::new('price', 'Ціна, грн')
            ->setNumDecimals(2)->setColumns(4)
            ->setHelp('Порожньо = показується довільний текст нижче')
            ->setHtmlAttributes($this->hint(
                'Число. Лишіть порожнім, якщо ціни немає - тоді показується текст із поля «Текст '
                . 'замість ціни» («За оцінкою обʼєкта»). Такі рядки калькулятор пропускає.'
            ));
        yield TextField::new('pricePrefix', 'Префікс «від»')->hideOnIndex()->setColumns(4)
            ->setHelp('Будь-яке непорожнє значення = показ «від/от» перед ціною; порожньо = точна ціна');
        yield FormField::addRow();
        yield TextField::new('priceTextUk', 'Текст замість ціни uk')->hideOnIndex()->setColumns(6)
            ->setHelp('«За домовленістю» тощо; використовується, коли числова ціна порожня');
        yield TextField::new('priceTextRu', 'Текст замість ціни ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Калькулятор');
        yield BooleanField::new('inCalculator', 'У калькуляторі')->setColumns(4)
            ->setHelp('Показувати серед додаткових послуг у розрахунку')
            ->setHtmlAttributes($this->hint(
                'Увімкнено - позиція зʼявляється серед додаткових послуг у розрахунку вартості. '
                . 'Вимкнено - видно тільки в таблиці на сторінці.'
            ));
        yield BooleanField::new('perUnit', 'За кількість')->hideOnIndex()->setColumns(4)
            ->setHelp('Увімкнено - поле «скільки» (м², шт); вимкнено - галочка з разовою сумою');
        yield IntegerField::new('maxQty', 'Максимум кількості')->hideOnIndex()->setColumns(4);
        yield TextField::new('unitUk', 'Одиниця uk')->hideOnIndex()->setColumns(6)
            ->setHelp('«м²», «шт», «посадкове місце»');
        yield TextField::new('unitRu', 'Одиниця ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Налаштування');
        yield IntegerField::new('position', 'Порядок')->setColumns(6)
            ->setHtmlAttributes($this->hint(
                'Чим менше число, тим вище рядок у таблиці. Крок 10 зручний: між 10 і 20 можна '
                . 'вставити новий, не перенумеровуючи решту.'
            ));
        yield BooleanField::new('active', 'Активна')
            ->setColumns(6)
            ->setHtmlAttributes($this->hint('Вимкнена позиція зникає і з таблиці, і з калькулятора, але лишається тут.'));
    }
}
