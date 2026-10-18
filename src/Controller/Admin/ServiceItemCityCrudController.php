<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceItemCity;
use App\Form\ServiceFaqItemType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Тексти послуги під місто (ServiceItemCity): сторінка послуги на міському хості бере заповнені тут поля,
 * порожні — з самої послуги. Назва тут — лише для H1/крихт/JSON-LD сторінки, меню й списки беруть назву послуги.
 */
#[IsGranted('ROLE_MODERATOR')]
class ServiceItemCityCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    public static function getEntityFqcn(): string
    {
        return ServiceItemCity::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Тексти послуги під місто')
            ->setEntityLabelInPlural('Послуги: тексти по містах')
            ->setDefaultSort(['city' => 'ASC', 'item' => 'ASC'])
            ->setSearchFields(['titleUk', 'titleRu', 'item.titleUk', 'city.nameUk'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('city', 'Місто'))
            ->add(EntityFilter::new('item', 'Послуга'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield from $this->withHints($this->fieldList());
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield FormField::addTab('Основне', 'fa fa-gear');
        yield AssociationField::new('item', 'Послуга')->setColumns(6)
            ->setHelp('Послуга сайту, тексти якої перевизначаються для міста.');
        yield AssociationField::new('city', 'Місто')->setColumns(6)
            ->setHelp('Місто (хост), для якого діють ці тексти. Одна пара послуга+місто.');
        yield FormField::addFieldset('Назва на сторінці');
        yield TextField::new('titleUk', 'Назва uk')->setColumns(6)
            ->setHelp('H1, крихти й JSON-LD сторінки послуги в цьому місті. Порожньо = назва послуги. Меню й списки — завжди назва послуги.');
        yield TextField::new('titleRu', 'Назва ru')->hideOnIndex()->setColumns(6);
        yield TextField::new('tabTitleUk', 'Назва вкладки uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Коротка назва для вкладок/перемикачів, де вона використовується.');
        yield TextField::new('tabTitleRu', 'Назва вкладки ru')->hideOnIndex()->setColumns(6);

        yield FormField::addTab('Тексти', 'fa fa-align-left');
        yield TextareaField::new('leadUk', 'Лід uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Абзац під H1; порожньо = лід послуги.');
        yield TextareaField::new('leadRu', 'Лід ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('descUk', 'Опис uk')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('descRu', 'Опис ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc2Uk', 'Опис 2 uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Блок під прайсом.');
        yield TextEditorField::new('desc2Ru', 'Опис 2 ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc3Uk', 'Опис 3 uk')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc3Ru', 'Опис 3 ru')->hideOnIndex()->setColumns(6);
        yield CollectionField::new('faqUk', 'FAQ uk')->hideOnIndex()->setColumns(6)
            ->setEntryType(ServiceFaqItemType::class)->setEntryIsComplex()
            ->setHelp('Порожньо = FAQ послуги.');
        yield CollectionField::new('faqRu', 'FAQ ru')->hideOnIndex()->setColumns(6)
            ->setEntryType(ServiceFaqItemType::class)->setEntryIsComplex();

        yield FormField::addTab('SEO', 'fa fa-magnifying-glass');
        yield TextField::new('metaTitleUk', 'Meta title uk')->hideOnIndex()->setColumns(6)
            ->setHelp('До ~60 символів; порожньо = мета послуги або згенерований.');
        yield TextField::new('metaTitleRu', 'Meta title ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('metaDescUk', 'Meta description uk')->hideOnIndex()->setColumns(6)
            ->setHelp('~150-160 символів');
        yield TextareaField::new('metaDescRu', 'Meta description ru')->hideOnIndex()->setColumns(6);
    }
}
