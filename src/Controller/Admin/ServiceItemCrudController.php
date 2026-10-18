<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceItem;
use App\Form\ServiceFaqItemType;
use App\Form\ExtraFieldsType;
use App\ServiceSite\CustomFields;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
class ServiceItemCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;
    use ImageFieldsTrait;

    /** Додаткові поля запису з паку (app.custom_fields) */
    public function __construct(private readonly CustomFields $customFields)
    {
    }

    /** У списку виноситься наперед: назва послуги, а не категорія */
    private const INDEX_PRIMARY = 'titleUk';

    public static function getEntityFqcn(): string
    {
        return ServiceItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Послуга')
            ->setEntityLabelInPlural('Послуги')
            ->setDefaultSort(['category' => 'ASC', 'position' => 'ASC'])
            ->setSearchFields(['titleUk', 'titleRu', 'slugUk', 'slugRu'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('category', 'Категорія'))
            ->add(EntityFilter::new('site', 'Сайт'))
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
        // індекс (таби діють лише на форму; порядок колонок = порядок оголошення)
        yield IdField::new('id')->onlyOnIndex();

        yield FormField::addTab('Основне', 'fa fa-gear');
        yield FormField::addFieldset('Розміщення');
        yield AssociationField::new('site', 'Сайт')->hideOnIndex()->setColumns(6);
        yield AssociationField::new('category', 'Категорія')->setColumns(6)
            ->setHelp('Група в каталозі, під якою послуга показується.');
        yield FormField::addFieldset('Назва і слаг');
        yield TextField::new('titleUk', 'Назва uk')->setColumns(6)
            ->setHelp('Назва послуги, як її бачить відвідувач і як вона стоїть у заголовку сторінки.');
        yield TextField::new('titleRu', 'Назва ru')->hideOnIndex()->setColumns(6);
        yield TextField::new('slugUk', 'Слаг uk')->setColumns(6);
        yield TextField::new('slugRu', 'Слаг ru')->hideOnIndex()->setColumns(6)
            ->setHelp('Порожньо = використовується український слаг');
        yield TextField::new('nameUk', 'Коротка назва uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Для карток і меню («Самоскиди»). Порожньо = повна назва.');
        yield TextField::new('nameRu', 'Коротка назва ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Налаштування');
        yield IntegerField::new('position', 'Порядок')->setColumns(4)
            ->setHelp('Порядок усередині категорії.');
        yield BooleanField::new('utp', 'УТП-блок')->hideOnIndex()->setColumns(4)
            ->setHelp('Увімкнено = блок «переваги», вимкнено = блок «довіра»');
        yield BooleanField::new('active', 'Активна')->setColumns(4)
            ->setHelp('Вимкнена послуга зникає з каталогу, меню і сторінок районів.');
        yield FormField::addFieldset('Міста');
        yield AssociationField::new('hiddenCities', 'Не надається в містах')->hideOnIndex()->setColumns(6)
            ->setFormTypeOption('by_reference', false)
            ->setHelp('Оберіть міста, де цієї послуги нема: там вона зникає з каталогу, головної, районів, футера і карти сайту, '
                . 'а її сторінка веде на місто, де послуга є. Порожньо = послуга в усіх містах.');
        yield AssociationField::new('priceHiddenCities', 'Ціни не показувати в містах')->hideOnIndex()->setColumns(6)
            ->setFormTypeOption('by_reference', false)
            ->setHelp('Послуга в цих містах є, але без цін: без таблиці цін, вкладки на головній і калькулятора. '
                . 'Самі ціни лишаються в розділі «Ціни». Порожньо = ціни всюди, де вони заповнені.');
        yield AssociationField::new('calcType', 'Калькулятор: тип прибирання')->hideOnIndex()
            ->setColumns(6)
            ->setHelp('Обраний тип вмикає міні-калькулятор на сторінці послуги; порожньо - без калькулятора');
        yield FormField::addFieldset('Зображення');
        yield from $this->imageFields('/media/service_item');

        yield FormField::addTab('Тексти', 'fa fa-align-left');
        yield FormField::addFieldset('Лід (короткий вступ)');
        yield TextareaField::new('leadUk', 'Лід uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Один-два рядки під заголовком. Ідуть також в опис для Google і в мікророзмітку.');
        yield TextareaField::new('leadRu', 'Лід ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Описи');
        yield TextEditorField::new('descUk', 'Опис uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Основний текст сторінки, над таблицею цін.');
        yield TextEditorField::new('descRu', 'Опис ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc2Uk', 'Опис 2 uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Другий блок - під таблицею цін.');
        yield TextEditorField::new('desc2Ru', 'Опис 2 ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc3Uk', 'Опис 3 uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Третій блок - у самому низу.');
        yield TextEditorField::new('desc3Ru', 'Опис 3 ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Інше');
        yield TextField::new('tabTitleUk', 'Підпис вкладки цін uk')->hideOnIndex()->setColumns(6);
        yield TextField::new('tabTitleRu', 'Підпис вкладки цін ru')->hideOnIndex()->setColumns(6);

        yield FormField::addTab('FAQ', 'fa fa-circle-question');
        yield CollectionField::new('faqUk', 'FAQ uk')->hideOnIndex()->setColumns(6)
            ->setEntryType(ServiceFaqItemType::class)
            ->allowAdd()->allowDelete()
            ->setHelp('Питання і відповіді внизу сторінки. Потрапляють у мікророзмітку FAQ.');
        yield CollectionField::new('faqRu', 'FAQ ru')->hideOnIndex()->setColumns(6)
            ->setEntryType(ServiceFaqItemType::class)
            ->allowAdd()->allowDelete()
            ->setHelp('Порядок пунктів у ru має збігатися з uk');

        if ($this->customFields->has('item')) {
            yield FormField::addTab('Додаткові поля', 'fa fa-list');
            yield Field::new('extra', 'Додаткові поля')->hideOnIndex()->setFormType(ExtraFieldsType::class)
                ->setFormTypeOption('record_type', 'item')
                ->setHelp('Набір полів задає пак сайту (app.custom_fields). Показуються таблицею на сторінці й у картці.');
        }

        yield FormField::addTab('SEO', 'fa fa-magnifying-glass');
        yield TextField::new('metaTitleUk', 'Meta title uk')->hideOnIndex()->setColumns(6)
            ->setHelp('До ~60 символів');
        yield TextField::new('metaTitleRu', 'Meta title ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('metaDescUk', 'Meta description uk')->hideOnIndex()->setColumns(6)
            ->setHelp('~150-160 символів');
        yield TextareaField::new('metaDescRu', 'Meta description ru')->hideOnIndex()->setColumns(6);

        // індикатор перекладу в списку (той самий titleRu, інший рендер)
        yield TextField::new('titleRu', 'ru')->onlyOnIndex()
            ->formatValue(static fn ($v) => $v !== null && $v !== '' ? '✓' : '—');
    }
}
