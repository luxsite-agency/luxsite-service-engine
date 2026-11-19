<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceTeamMemberImage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Документ лікаря (ServiceTeamMemberImage). Окремого пункту меню нема: форма вбудована у вкладку «Дипломи та сертифікати»
 * співробітника (ServiceTeamMemberCrudController, CollectionField::useEntryCrudForm).
 */
#[IsGranted('ROLE_MODERATOR')]
class ServiceTeamMemberImageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ServiceTeamMemberImage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Документ лікаря')
            ->setEntityLabelInPlural('Документи лікарів')
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
            ->setHelp('JPG/PNG/WebP до 10 МБ, краще не ширше 1600 px. На сайті мініатюра, клік відкриває скан цілком.')
            ->setColumns(12)
            ->onlyOnForms();
        yield ImageField::new('imageName', 'Зображення')->setBasePath('/media/service_team')->onlyOnIndex();
        yield TextField::new('captionUk', 'Назва документа')->setColumns(5)
            ->setHelp('Під зображенням і в alt: «Сертифікат лікаря-спеціаліста», «Диплом спеціаліста».');
        yield TextField::new('noteUk', 'Деталі')->setColumns(5)
            ->setHelp('Дрібним рядком: спеціальність, заклад, рік, термін дії.');
        yield IntegerField::new('position', 'Порядок')->setColumns(2)
            ->setHelp('Менше = раніше.');
    }
}
