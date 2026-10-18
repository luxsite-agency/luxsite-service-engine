<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCategoryCity;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Тексти категорії під місто (ServiceCategoryCity): /services/<категорія>/ на міському хості; порожні поля — з категорії. */
#[IsGranted('ROLE_MODERATOR')]
class ServiceCategoryCityCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    public static function getEntityFqcn(): string
    {
        return ServiceCategoryCity::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Тексти категорії під місто')
            ->setEntityLabelInPlural('Категорії: тексти по містах')
            ->setDefaultSort(['city' => 'ASC', 'category' => 'ASC'])
            ->setSearchFields(['titleUk', 'titleRu', 'category.titleUk', 'city.nameUk'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('city', 'Місто'))
            ->add(EntityFilter::new('category', 'Категорія'));
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
        yield AssociationField::new('category', 'Категорія')->setColumns(6);
        yield AssociationField::new('city', 'Місто')->setColumns(6)
            ->setHelp('Одна пара категорія+місто.');
        yield TextField::new('titleUk', 'Назва uk')->setColumns(6)
            ->setHelp('H1 і крихти сторінки категорії в цьому місті; порожньо = назва категорії. Меню — завжди назва категорії.');
        yield TextField::new('titleRu', 'Назва ru')->hideOnIndex()->setColumns(6);

        yield FormField::addTab('Тексти', 'fa fa-align-left');
        yield TextEditorField::new('descUk', 'Опис uk')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('descRu', 'Опис ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc2Uk', 'Опис 2 uk')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc2Ru', 'Опис 2 ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc3Uk', 'Опис 3 uk')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc3Ru', 'Опис 3 ru')->hideOnIndex()->setColumns(6);

        yield FormField::addTab('SEO', 'fa fa-magnifying-glass');
        yield TextField::new('metaTitleUk', 'Meta title uk')->hideOnIndex()->setColumns(6);
        yield TextField::new('metaTitleRu', 'Meta title ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('metaDescUk', 'Meta description uk')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('metaDescRu', 'Meta description ru')->hideOnIndex()->setColumns(6);
    }
}
