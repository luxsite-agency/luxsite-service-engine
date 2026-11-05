<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceDistrict;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
class ServiceDistrictCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: назва району, а не місто */
    private const INDEX_PRIMARY = 'nameUk';

    public function __construct(
        #[Autowire('%app.locales%')]
        private readonly array $locales = ['uk', 'ru'],
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceDistrict::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Район')
            ->setEntityLabelInPlural('Райони')
            ->setDefaultSort(['city' => 'ASC', 'position' => 'ASC'])
            ->setSearchFields(array_merge(['nameUk', 'nameRu', 'slugUk', 'slugRu', 'groupUk'], \in_array('en', $this->locales, true) ? ['nameEn', 'slugEn', 'groupEn'] : []))
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('city', 'Місто'))
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
        yield AssociationField::new('city', 'Місто')->setColumns(6)
            ->setHelp('Місто, у якому цей район. Сторінка буде доступна лише на його сайті.');
        yield FormField::addFieldset('Назва і слаг');
        yield TextField::new('nameUk', 'Назва uk')->setRequired(!\in_array('en', $this->locales, true) || \in_array('uk', $this->locales, true))->setFormTypeOption('empty_data', '')->setColumns(6)
            ->setHelp('Назва району, як її говорять місцеві: «Поділ», «Холодна Гора».');
        if (\in_array('en', $this->locales, true)) {
            yield TextField::new('nameEn', 'Назва en')->setColumns(6)->hideOnIndex()->setRequired(false);
        }
        yield TextField::new('nameRu', 'Назва ru')->hideOnIndex()->setColumns(6);
        yield TextField::new('slugUk', 'Слаг uk')->setRequired(!\in_array('en', $this->locales, true) || \in_array('uk', $this->locales, true))->setFormTypeOption('empty_data', '')->setColumns(6)
            ->setHelp('Частина адреси латиницею. ⚠️ Зміна ламає посилання, які вже проіндексовані.');
        if (\in_array('en', $this->locales, true)) {
            yield TextField::new('slugEn', 'Слаг en')->setColumns(6)->hideOnIndex()->setRequired(false)->setHelp('Порожньо = використовується український слаг');
        }
        yield TextField::new('slugRu', 'Слаг ru')->hideOnIndex()->setColumns(6)
            ->setHelp('Порожньо = використовується український слаг');
        yield TextField::new('groupUk', 'Група (адмінрайон) uk')->setColumns(6)
            ->setHelp('Заголовок в акордеоні «Де ми працюємо», напр. «Оболонський район»; порожньо = група «Інші райони»');
        if (\in_array('en', $this->locales, true)) {
            yield TextField::new('groupEn', 'Група (адмінрайон) en')->setColumns(6)->hideOnIndex()->setRequired(false);
        }
        yield TextField::new('groupRu', 'Група (адмінрайон) ru')->hideOnIndex()->setColumns(6);
        yield FormField::addFieldset('Налаштування');
        yield IntegerField::new('minPrice', 'Мін. виїзд, грн')->hideOnIndex()->setColumns(4)
            ->setHelp('Ціна «від», яку видно в шапці сторінки району. Порожньо - блок не показується.');
        yield IntegerField::new('position', 'Порядок')->setColumns(4)
            ->setHelp(
                'Порядок у списку районів. Заодно визначає, яке фото береться з загального пулу, якщо '
                . 'своє не задане.'
            );
        yield BooleanField::new('active', 'Активний')->setColumns(4);
        yield BooleanField::new('isPrimary', 'Офіційний район')->setColumns(4)
            ->setHelp('Верхній рівень лінк-мережі: такі сторінки виводяться на головній, у хабі й на послугах');
        yield TextField::new('imageName', 'Файл обкладинки')->hideOnIndex()
            ->setHelp('Порожньо - шаблон бере фото з пулу district-1..10');
        yield TextField::new('imageAlt', 'Опис обкладинки')->hideOnIndex()
            ->setHelp('Опис фото для пошуку і читачів екрана. Українською, навіть на ru-сторінці.');
        yield ArrayField::new('faqUk', 'FAQ uk')->hideOnIndex()
            ->setHelp('Масив {q, a}; дає блок питань і FAQPage-розмітку');
        if (\in_array('en', $this->locales, true)) {
            yield ArrayField::new('faqEn', 'FAQ en')->hideOnIndex()->setRequired(false);
        }
        yield ArrayField::new('faqRu', 'FAQ ru')->hideOnIndex();

        yield FormField::addTab('Тексти', 'fa fa-align-left');
        yield TextField::new('titleUk', 'Заголовок uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Заголовок H1. Порожньо - збирається автоматично з назви району.');
        if (\in_array('en', $this->locales, true)) {
            yield TextField::new('titleEn', 'Заголовок en')->hideOnIndex()->setColumns(6)->setRequired(false);
        }
        yield TextField::new('titleRu', 'Заголовок ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('leadUk', 'Лід uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Один-два рядки під заголовком, жирним.');
        if (\in_array('en', $this->locales, true)) {
            yield TextareaField::new('leadEn', 'Лід en')->hideOnIndex()->setColumns(6)->setRequired(false);
        }
        yield TextareaField::new('leadRu', 'Лід ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('descUk', 'Опис uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Основний текст сторінки району.');
        if (\in_array('en', $this->locales, true)) {
            yield TextEditorField::new('descEn', 'Опис en')->hideOnIndex()->setColumns(6)->setRequired(false);
        }
        yield TextEditorField::new('descRu', 'Опис ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('subAreasUk', 'Вулиці/мікрорайони uk')->hideOnIndex()->setColumns(6)
            ->setHelp('По одному на рядок - для акордеона районів');
        if (\in_array('en', $this->locales, true)) {
            yield TextareaField::new('subAreasEn', 'Вулиці/мікрорайони en')->hideOnIndex()->setColumns(6)->setRequired(false);
        }
        yield TextareaField::new('subAreasRu', 'Вулиці/мікрорайони ru')->hideOnIndex()->setColumns(6);

        yield FormField::addTab('SEO', 'fa fa-magnifying-glass');
        yield TextField::new('metaTitleUk', 'Meta title uk')->hideOnIndex()->setColumns(6)
            ->setHelp('До ~60 символів');
        if (\in_array('en', $this->locales, true)) {
            yield TextField::new('metaTitleEn', 'Meta title en')->hideOnIndex()->setColumns(6)->setRequired(false);
        }
        yield TextField::new('metaTitleRu', 'Meta title ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('metaDescUk', 'Meta description uk')->hideOnIndex()->setColumns(6)
            ->setHelp('~150-160 символів');
        if (\in_array('en', $this->locales, true)) {
            yield TextareaField::new('metaDescEn', 'Meta description en')->hideOnIndex()->setColumns(6)->setRequired(false);
        }
        yield TextareaField::new('metaDescRu', 'Meta description ru')->hideOnIndex()->setColumns(6);

        yield TextField::new('nameRu', 'ru')->onlyOnIndex()
            ->formatValue(static fn ($v) => $v !== null && $v !== '' ? '✓' : '—');
    }
}
