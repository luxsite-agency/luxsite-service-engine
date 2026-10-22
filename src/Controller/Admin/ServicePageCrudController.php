<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServicePage;
use App\Form\ExtraFieldsType;
use App\ServiceSite\CustomFields;
use App\ServiceSite\RootSlugs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Сторінки app.pages (ServicePage): вакансії, ліцензії, будь-який текст поза каталогом, від кореня сайту.
 * Пункт меню є лише з app.pages: true (DashboardController).
 */
#[IsGranted('ROLE_MODERATOR')]
class ServicePageCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;
    use ImageFieldsTrait;
    use RootSlugConstraintsTrait;

    /** Додаткові поля з паку (app.custom_fields.page); RootSlugs — збіг адреси з послугою, категорією, фіксованим розділом */
    public function __construct(
        private readonly CustomFields $customFields,
        private readonly RootSlugs $rootSlugs,
    ) {
    }

    /** У списку виноситься наперед: заголовок сторінки, а не сайт */
    private const INDEX_PRIMARY = 'titleUk';

    public static function getEntityFqcn(): string
    {
        return ServicePage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Сторінка')
            ->setEntityLabelInPlural('Сторінки')
            ->setDefaultSort(['site' => 'ASC', 'parent' => 'ASC', 'position' => 'ASC'])
            ->setSearchFields(['titleUk', 'titleRu', 'slugUk', 'slugRu'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('site', 'Сайт'))
            ->add(EntityFilter::new('parent', 'Батьківська'))
            ->add(BooleanFilter::new('active', 'Активна'));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions);
    }

    /**
     * Батька з дочірніми не видаляємо: зовнішній ключ (SET NULL) тихо переніс би їх у корінь сайту з новими адресами,
     * а каскад зніс би всі вакансії розділу. Спершу перенести або видалити дочірні.
     */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ServicePage && !$entityInstance->getChildren()->isEmpty()) {
            $this->addFlash('danger', sprintf('Сторінку «%s» не видалено: у неї є дочірні (%d). Спершу перенесіть або видаліть їх.',
                $entityInstance->getTitleUk(), $entityInstance->getChildren()->count()));

            return;
        }
        parent::deleteEntity($entityManager, $entityInstance);
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
        yield FormField::addFieldset('Розміщення');
        yield AssociationField::new('site', 'Сайт')->hideOnIndex()->setColumns(6)
            ->setHelp('Бренд (сайт), якому належить сторінка.');
        yield AssociationField::new('parent', 'Батьківська сторінка')->setColumns(6)
            ->setQueryBuilder(static fn (QueryBuilder $qb): QueryBuilder => $qb->andWhere('entity.parent IS NULL'))
            ->setHelp('Порожньо = адреса /<слаг>/. Обрано «Вакансії» = /vakansii/<слаг>/, і сторінка з’явиться в списку на «Вакансіях». '
                . 'Лише один рівень: батьком може бути тільки сторінка без батька.');
        yield FormField::addFieldset('Заголовок і адреса');
        yield TextField::new('titleUk', 'Заголовок uk')->setColumns(6)
            ->setHelp('H1 сторінки і назва в списках, футері, хлібних крихтах.');
        yield TextField::new('titleRu', 'Заголовок ru')->hideOnIndex()->setColumns(6)
            ->setHelp('Порожньо - показується український заголовок.');
        yield TextField::new('slugUk', 'Слаг uk')->hideOnIndex()->setColumns(6)
            ->setFormTypeOption('constraints', $this->rootSlugConstraints('uk'))
            ->setHelp('Частина адреси латиницею: licenzii-i-sertifikaty → /licenzii-i-sertifikaty/. ⚠️ Зміна ламає посилання, які вже є в Google. '
                . 'Не може збігатися зі слагом послуги, категорії чи фіксованого розділу (about, contacts, services…).');
        yield TextField::new('slugRu', 'Слаг ru')->hideOnIndex()->setColumns(6)
            ->setFormTypeOption('constraints', $this->rootSlugConstraints('ru'))
            ->setHelp('Порожньо = український слаг: /ru/<слаг uk>/.');
        yield TextField::new('slugUk', 'Адреса')->onlyOnIndex()
            ->formatValue(static fn ($v, ?ServicePage $p) => $p !== null ? '/' . $p->path('uk') . '/' : '');
        yield FormField::addFieldset('Показ');
        yield IntegerField::new('position', 'Порядок')->setColumns(4)
            ->setHelp('Порядок серед сторінок одного батька і в футері: менше число — вище.');
        yield BooleanField::new('inFooter', 'У футері')->setColumns(4)
            ->setHelp('Посилання на сторінку в нижньому меню сайту, поруч із «Контактами».');
        yield BooleanField::new('active', 'Активна')->setColumns(4)
            ->setHelp('Вимкнена сторінка (і всі її дочірні) віддає 404 і зникає з карти сайту.');
        yield AssociationField::new('hiddenCities', 'Не показувати в містах')->hideOnIndex()->setColumns(6)
            ->setFormTypeOption('by_reference', false)
            ->setHelp('На хостах цих міст (філій) сторінка віддає 404 і її нема в карті сайту й футері. Порожньо = на всіх.');
        yield FormField::addFieldset('Обкладинка');
        yield from $this->imageFields('/media/service_page');

        yield FormField::addTab('Текст', 'fa fa-align-left');
        yield TextareaField::new('leadUk', 'Вступ uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Абзац під заголовком; він же опис у списку дочірніх і meta description, якщо свого нема.');
        yield TextareaField::new('leadRu', 'Вступ ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('descUk', 'Текст uk')->hideOnIndex()->setColumns(12)
            ->setHelp('Основний текст сторінки.');
        yield TextEditorField::new('descRu', 'Текст ru')->hideOnIndex()->setColumns(12);

        yield FormField::addTab('Галерея', 'fa fa-images');
        yield CollectionField::new('images', 'Зображення')->hideOnIndex()->setColumns(12)
            ->useEntryCrudForm(ServicePageImageCrudController::class)
            ->setFormTypeOption('by_reference', false)
            ->allowAdd()->allowDelete()
            ->setHelp('Скани ліцензій і сертифікатів, фото: сіткою під текстом, без обрізання, клік відкриває оригінал.');

        if ($this->customFields->has('page')) {
            yield FormField::addTab('Додаткові поля', 'fa fa-list');
            yield Field::new('extra', 'Додаткові поля')->hideOnIndex()->setFormType(ExtraFieldsType::class)
                ->setFormTypeOption('record_type', 'page')
                ->setHelp('Набір полів задає пак сайту (app.custom_fields.page).');
        }

        yield FormField::addTab('SEO', 'fa fa-magnifying-glass');
        yield TextField::new('metaTitleUk', 'Meta title uk')->hideOnIndex()->setColumns(6)
            ->setHelp('До ~60 символів; порожньо = «Заголовок - назва сайту».');
        yield TextField::new('metaTitleRu', 'Meta title ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('metaDescUk', 'Meta description uk')->hideOnIndex()->setColumns(6)
            ->setHelp('~150-160 символів; порожньо = вступ.');
        yield TextareaField::new('metaDescRu', 'Meta description ru')->hideOnIndex()->setColumns(6);

        yield TextField::new('titleRu', 'ru')->onlyOnIndex()
            ->formatValue(static fn ($v) => $v !== null && $v !== '' ? '✓' : '—');
    }
}
