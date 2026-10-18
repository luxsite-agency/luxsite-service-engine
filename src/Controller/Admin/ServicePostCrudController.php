<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServicePost;
use App\Form\ExtraFieldsType;
use App\ServiceSite\CustomFields;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
class ServicePostCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;
    use ImageFieldsTrait;

    /** Додаткові поля запису з паку (app.custom_fields) */
    public function __construct(private readonly CustomFields $customFields)
    {
    }

    /** У списку виноситься наперед: заголовок статті, а не сайт */
    private const INDEX_PRIMARY = 'titleUk';

    public static function getEntityFqcn(): string
    {
        return ServicePost::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Пост блогу')
            ->setEntityLabelInPlural('Блог сервісів')
            ->setDefaultSort(['publishedAt' => 'DESC'])
            ->setSearchFields(['titleUk', 'titleRu', 'slugUk', 'slugRu'])
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

        yield FormField::addTab('Основне', 'fa fa-gear');
        yield AssociationField::new('site', 'Сайт')->setColumns(6)
            ->setHelp('Бренд, у блозі якого вийде стаття.');
        yield FormField::addFieldset('Заголовок і слаг');
        yield TextField::new('titleUk', 'Заголовок uk')->setColumns(6)
            ->setHelp('Заголовок статті.');
        yield TextField::new('titleRu', 'Заголовок ru')->hideOnIndex()->setColumns(6);
        yield TextField::new('slugUk', 'Слаг uk')->setColumns(6)
            ->setHelp('Частина адреси латиницею. ⚠️ Після публікації краще не міняти.');
        yield TextField::new('slugRu', 'Слаг ru')->hideOnIndex()->setColumns(6)
            ->setHelp('Порожньо = використовується український слаг');
        yield FormField::addFieldset('Публікація');
        yield DateTimeField::new('publishedAt', 'Опубліковано')->setColumns(6)
            ->setHelp('Порожньо = чернетка, на сайті не видно');
        yield BooleanField::new('active', 'Активний')->setColumns(6)
            ->setHelp('Вимкнена стаття зникає з блогу і з карти сайту.');
        yield FormField::addFieldset('Зображення');
        yield from $this->imageFields('/media/service_post');

        yield FormField::addTab('Текст', 'fa fa-align-left');
        yield FormField::addFieldset('Лід');
        yield TextareaField::new('leadUk', 'Лід uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Короткий вступ: видно в списку статей і в анонсі для Google.');
        yield TextareaField::new('leadRu', 'Лід ru')->hideOnIndex()->setColumns(6);
        // статті довгі - редактору потрібна повна ширина; ru одразу під uk
        yield FormField::addFieldset('Текст статті');
        yield TextEditorField::new('descUk', 'Текст uk')->hideOnIndex()->setColumns(12)
            ->setHelp('Текст статті.');
        yield TextEditorField::new('descRu', 'Текст ru')->hideOnIndex()->setColumns(12);

        if ($this->customFields->has('post')) {
            yield FormField::addTab('Додаткові поля', 'fa fa-list');
            yield Field::new('extra', 'Додаткові поля')->hideOnIndex()->setFormType(ExtraFieldsType::class)
                ->setFormTypeOption('record_type', 'post')
                ->setHelp('Набір полів задає пак сайту (app.custom_fields).');
        }

        yield FormField::addTab('SEO', 'fa fa-magnifying-glass');
        yield TextField::new('metaTitleUk', 'Meta title uk')->hideOnIndex()->setColumns(6)
            ->setHelp('До ~60 символів');
        yield TextField::new('metaTitleRu', 'Meta title ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('metaDescUk', 'Meta description uk')->hideOnIndex()->setColumns(6)
            ->setHelp('~150-160 символів');
        yield TextareaField::new('metaDescRu', 'Meta description ru')->hideOnIndex()->setColumns(6);

        yield TextField::new('titleRu', 'ru')->onlyOnIndex()
            ->formatValue(static fn ($v) => $v !== null && $v !== '' ? '✓' : '—');
    }
}
