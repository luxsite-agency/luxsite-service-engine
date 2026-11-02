<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCategory;
use App\Form\ServiceFaqItemType;
use App\Form\ExtraFieldsType;
use App\ServiceSite\CustomFields;
use App\ServiceSite\RootSlugs;
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
class ServiceCategoryCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;
    use ImageFieldsTrait;
    use RootSlugConstraintsTrait;

    /**
     * Додаткові поля запису з паку (app.custom_fields); RootSlugs — заборонені слаги, коли категорія живе в корені
     * (схеми root і slug): слаг фіксованого розділу (services, districts, about, faq, префікс блогу…), «ru», слаг
     * сторінки app.pages чи (у slug) послуги зробив би одну з адрес недосяжною. У flat/nested обмеження нема.
     */
    public function __construct(
        private readonly CustomFields $customFields,
        private readonly RootSlugs $rootSlugs,
    ) {
    }

    /** У списку виноситься наперед: назва категорії, а не сайт */
    private const INDEX_PRIMARY = 'titleUk';

    public static function getEntityFqcn(): string
    {
        return ServiceCategory::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Категорія послуг')
            ->setEntityLabelInPlural('Категорії послуг')
            ->setDefaultSort(['site' => 'ASC', 'position' => 'ASC'])
            ->setSearchFields(['titleUk', 'titleRu', 'slugUk', 'slugRu'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
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
        yield IdField::new('id')->onlyOnIndex();

        yield FormField::addTab('Основне', 'fa fa-gear');
        yield AssociationField::new('site', 'Сайт')->setColumns(6)
            ->setHelp('Бренд (сайт), якому належить категорія.');
        yield FormField::addFieldset('Назва і слаг');
        yield TextField::new('titleUk', 'Назва uk')->setColumns(6)
            ->setHelp('Назва групи послуг у каталозі: «Хімчистка меблів і килимів».');
        yield TextField::new('titleRu', 'Назва ru')->hideOnIndex()->setColumns(6)
            ->setHelp('Порожньо - показується українська назва.');
        yield TextField::new('slugUk', 'Слаг uk')->setColumns(6)
            ->setFormTypeOption('constraints', $this->rootSlugConstraints('uk'))
            ->setHelp(
                'Частина адреси латиницею. ⚠️ Зміна ламає всі посилання на категорію, які вже є в '
                . 'Google.'
            );
        yield TextField::new('slugRu', 'Слаг ru')->hideOnIndex()->setColumns(6)
            ->setFormTypeOption('constraints', $this->rootSlugConstraints('ru'))
            ->setHelp('Порожньо = використовується український слаг');
        yield FormField::addFieldset('Налаштування');
        yield IntegerField::new('position', 'Порядок')->setColumns(6)
            ->setHelp('Порядок у каталозі: чим менше число, тим вище категорія.');
        yield BooleanField::new('active', 'Активна')->setColumns(6)
            ->setHelp('Вимкнена категорія зникає з каталогу разом зі своїми послугами.');
        yield BooleanField::new('landing', 'Посадкова сторінка')->setColumns(6)
            ->setHelp('Так: повна сторінка категорії (верх як у послуги, лід, сітка послуг, SEO-блоки), у sitemap, з крихт і каталогу на неї є посилання. '
                . 'Ні: адреси послуг не змінюються, але сторінка категорії noindex, без sitemap і без посилань на неї. Діє в схемах URL зі сторінками категорій (nested, root, slug).');
        yield FormField::addFieldset('Зображення');
        yield from $this->imageFields('/media/service_category');

        yield FormField::addTab('Тексти', 'fa fa-align-left');
        yield TextareaField::new('leadUk', 'Лід uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Вступ під заголовком сторінки категорії. Порожньо = нема.');
        yield TextareaField::new('leadRu', 'Лід ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('descUk', 'Опис uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Текст угорі сторінки категорії, над переліком послуг.');
        yield TextEditorField::new('descRu', 'Опис ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc2Uk', 'Опис 2 uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Другий блок тексту - під переліком послуг.');
        yield TextEditorField::new('desc2Ru', 'Опис 2 ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('desc3Uk', 'Опис 3 uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Третій блок - у самому низу сторінки.');
        yield TextEditorField::new('desc3Ru', 'Опис 3 ru')->hideOnIndex()->setColumns(6);

        yield FormField::addTab('FAQ', 'fa fa-circle-question');
        yield CollectionField::new('faqUk', 'FAQ uk')->hideOnIndex()->setColumns(6)
            ->setEntryType(ServiceFaqItemType::class)
            ->allowAdd()->allowDelete()
            ->setHelp('Питання і відповіді внизу сторінки категорії (мікророзмітка FAQ).');
        yield CollectionField::new('faqRu', 'FAQ ru')->hideOnIndex()->setColumns(6)
            ->setEntryType(ServiceFaqItemType::class)
            ->allowAdd()->allowDelete()
            ->setHelp('Порядок пунктів у ru має збігатися з uk');

        if ($this->customFields->has('category')) {
            yield FormField::addTab('Додаткові поля', 'fa fa-list');
            yield Field::new('extra', 'Додаткові поля')->hideOnIndex()->setFormType(ExtraFieldsType::class)
                ->setFormTypeOption('record_type', 'category')
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
