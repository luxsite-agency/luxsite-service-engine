<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Поля головного зображення для CRUD (Vich): аплоад у формі + прев'ю в списку + alt.
 * $basePath — uri_prefix мапінгу ('/media/news' | '/media/event' | '/media/place').
 */
trait ImageFieldsTrait
{
    private function imageFields(string $basePath): iterable
    {
        yield Field::new('imageFile', 'Зображення')
            ->setFormType(VichImageType::class)
            ->setFormTypeOptions([
                'allow_delete' => true,
                'delete_label' => 'Видалити зображення',
                'download_uri' => false,
                'image_uri' => true,
            ])
            ->setHelp(
                'JPG/PNG/WebP до 10 МБ, від 1200×630. Широкий кадр: на сторінці фото '
                . 'обрізається по висоті, тому важливе має бути в центрі.'
            )
            ->onlyOnForms();
        yield ImageField::new('imageName', 'Фото')
            ->setBasePath($basePath)
            ->onlyOnIndex();
        yield TextField::new('imageAlt', 'Alt зображення')
            ->setHelp(
                'Що саме на фото, кількома словами. Читають пошук і програми для незрячих. '
                . 'Пишемо українською навіть на російській сторінці.'
            )
            ->hideOnIndex()
            ->setRequired(false);
    }
}
