<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServicePageImage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Рядок галереї сторінки (ServicePageImage). Окремого пункту меню нема: форма вбудована у вкладку «Галерея»
 * сторінки (ServicePageCrudController, CollectionField::useEntryCrudForm).
 */
#[IsGranted('ROLE_MODERATOR')]
class ServicePageImageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ServicePageImage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Зображення сторінки')
            ->setEntityLabelInPlural('Зображення сторінок')
            ->setDefaultSort(['position' => 'ASC'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFields(string $pageName): iterable
    {
        yield Field::new('imageFile', 'Зображення')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions([
                'allow_delete' => false,
                'download_uri' => false,
                'image_uri' => true,
            ])
            ->setHelp('JPG/PNG/WebP до 10 МБ. Скан показується цілком, без обрізання; клік відкриває оригінал.')
            ->setColumns(12)
            ->onlyOnForms();
        yield ImageField::new('imageName', 'Зображення')->setBasePath('/media/service_page')->onlyOnIndex();
        yield TextField::new('captionUk', 'Підпис uk')->setColumns(5)
            ->setHelp('Під зображенням і в alt: «Ліцензія МОЗ на медичну практику».');
        yield TextField::new('captionRu', 'Підпис ru')->setColumns(5)
            ->setHelp('Порожньо = український підпис.');
        yield IntegerField::new('position', 'Порядок')->setColumns(2)
            ->setHelp('Менше = раніше.');
    }
}
