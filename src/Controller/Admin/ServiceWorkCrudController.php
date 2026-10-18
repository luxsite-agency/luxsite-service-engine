<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceWork;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vich\UploaderBundle\Form\Type\VichImageType;

#[IsGranted('ROLE_MODERATOR')]
class ServiceWorkCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: назва роботи, а не місто */
    private const INDEX_PRIMARY = 'titleUk';

    public static function getEntityFqcn(): string
    {
        return ServiceWork::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Наша робота')
            ->setEntityLabelInPlural('Наші роботи')
            ->setDefaultSort(['position' => 'ASC', 'id' => 'DESC'])
            ->setSearchFields(['titleUk', 'titleRu', 'captionUk', 'captionRu'])
            ->setHelp(
                'index',
                'Власні фото з об’єктів. Показуються на сторінках послуг і в галереї. '
                . 'Порожнє місто = фото видно на всіх чотирьох сайтах, порожня послуга = на всіх сторінках послуг.'
            )
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
        yield IdField::new('id')->onlyOnIndex();

        yield FormField::addTab('Основне', 'fa fa-gear');
        yield FormField::addFieldset('Що це за робота');
        yield TextField::new('titleUk', 'Назва uk')->setColumns(6)
            ->setHelp('Коротко, що на фото: «Хімчистка офісного крісла». Видно підписом під знімком.');
        yield TextField::new('titleRu', 'Назва ru')->hideOnIndex()->setColumns(6)
            ->setHelp('Порожньо = на російській сторінці покажемо українську назву.');
        yield TextField::new('captionUk', 'Уточнення uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Необовʼязковий рядок дрібним: район, тип обʼєкта, чим працювали.');
        yield TextField::new('captionRu', 'Уточнення ru')->hideOnIndex()->setColumns(6);

        yield FormField::addFieldset('Де показувати');
        yield AssociationField::new('city', 'Місто')->hideOnIndex()->setColumns(6)
            ->setHelp('Порожньо = фото видно на всіх чотирьох сайтах. Ставте місто лише тоді, коли кадр упізнаваний саме там.');
        yield AssociationField::new('item', 'Послуга')->hideOnIndex()->setColumns(6)
            ->setHelp('Сторінка послуги, де фото показується першим. Порожньо = загальний кадр для всіх послуг і галереї.');
        yield IntegerField::new('position', 'Порядок')->setColumns(6)
            ->setHelp('Менше число = вище в галереї. Однакові числа впорядковуються за датою додавання.');
        yield BooleanField::new('active', 'Активна')->setColumns(6)
            ->setHelp('Вимкнене фото зникає з сайту, але лишається в адмінці.');

        yield FormField::addTab('Фото', 'fa fa-image');
        yield FormField::addFieldset('Основний кадр');
        yield Field::new('imageFile', 'Фото роботи')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions([
                'allow_delete' => true,
                'delete_label' => 'Видалити фото',
                'download_uri' => false,
                'image_uri' => true,
            ])
            ->setHelp(
                'JPG/PNG/WebP до 10 МБ, від 1200×675. Кадр широкий: на сторінці '
                . 'обрізається по висоті, тому головне тримайте в центрі.'
            )
            ->onlyOnForms();
        yield ImageField::new('imageName', 'Фото')
            ->setBasePath('/media/service_work')
            ->onlyOnIndex();
        yield TextField::new('imageAlt', 'Alt фото')->hideOnIndex()->setRequired(false)
            ->setHelp('Що саме на фото, кількома словами. Читають пошук і програми для незрячих.');

        yield FormField::addFieldset('Кадр «до» (якщо є справжня пара)');
        yield Field::new('beforeFile', 'Фото «до»')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions([
                'allow_delete' => true,
                'delete_label' => 'Видалити фото «до»',
                'download_uri' => false,
                'image_uri' => true,
            ])
            ->setHelp(
                'Заповнюйте лише якщо це той самий обʼєкт до роботи, з тієї ж точки. '
                . 'Без пари блок «до/після» не показується - і це нормально.'
            )
            ->onlyOnForms();
        yield TextField::new('beforeAlt', 'Alt фото «до»')->hideOnIndex()->setRequired(false);

        yield AssociationField::new('city', 'Місто')->onlyOnIndex()
            ->formatValue(static fn ($v, $e) => $e->getCity()?->getNameNomUk() ?? 'усі міста');
        yield AssociationField::new('item', 'Послуга')->onlyOnIndex()
            ->formatValue(static fn ($v, $e) => $e->getItem()?->getTitleUk() ?? 'усі послуги');
    }
}
