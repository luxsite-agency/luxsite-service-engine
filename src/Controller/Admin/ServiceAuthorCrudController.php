<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceAuthor;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * Автори статей контентного сайту (22.10.2026). Реальні люди або «Редакція <бренду>» — рушій персон не вигадує.
 */
#[IsGranted('ROLE_MODERATOR')]
class ServiceAuthorCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** @param list<string> $locales */
    public function __construct(
        #[Autowire('%app.locales%')]
        private readonly array $locales = ['uk', 'ru'],
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceAuthor::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Автор')
            ->setEntityLabelInPlural('Автори статей')
            ->setDefaultSort(['site' => 'ASC', 'position' => 'ASC'])
            ->setEntityPermission('ROLE_MODERATOR');
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
        yield AssociationField::new('site', 'Сайт');
        yield TextField::new('name', 'Ім\'я')->setHelp('Як підписуємо статті. Реальна людина або «Редакція <бренду>».');
        yield TextField::new('slug', 'Слаг')->setHelp('Адреса /author/<слаг>/ — латиниця, цифри, дефіс.');
        foreach ($this->locales as $l) {
            yield TextField::new('role' . ucfirst($l), 'Роль ' . $l)->hideOnIndex()->setColumns(6);
            yield TextEditorField::new('bio' . ucfirst($l), 'Біо ' . $l)->hideOnIndex()->setColumns(12);
        }
        yield Field::new('imageFile', 'Фото')->setFormType(VichImageType::class)
            ->setFormTypeOptions(['allow_delete' => true, 'delete_label' => 'Видалити фото', 'download_uri' => false, 'image_uri' => true])
            ->setHelp('Квадратне JPG/PNG/WebP до 5 МБ.')->onlyOnForms();
        yield ImageField::new('imageName', 'Фото')->setBasePath('/media/service_author')->onlyOnIndex();
        yield IntegerField::new('position', 'Порядок');
        yield BooleanField::new('active', 'Активний')->setHelp('Вимкненого автора не видно на сайті; статті лишаються без підпису.');
    }
}
