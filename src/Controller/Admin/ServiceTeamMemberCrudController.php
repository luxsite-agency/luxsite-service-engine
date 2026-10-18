<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceTeamMember;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
class ServiceTeamMemberCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;
    use ImageFieldsTrait;

    /** У списку виноситься наперед: імʼя, а не місто */
    private const INDEX_PRIMARY = 'nameUk';

    public static function getEntityFqcn(): string
    {
        return ServiceTeamMember::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Майстер')
            ->setEntityLabelInPlural('Команда')
            ->setDefaultSort(['city' => 'ASC', 'position' => 'ASC'])
            ->setEntityPermission('ROLE_MODERATOR');
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
        yield AssociationField::new('city', 'Місто')
            ->setHelp('Людину видно лише на сайті цього міста, у блоці «Команда».');
        yield TextField::new('nameUk', 'Ім\'я uk')
            ->setHelp('Імʼя, як його бачить відвідувач. Прізвище зазвичай не пишемо.');
        yield TextField::new('nameRu', 'Ім\'я ru')->hideOnIndex()
            ->setHelp('Порожньо - показується українське імʼя.');
        yield TextField::new('roleUk', 'Роль uk')
            ->setHelp('Посада під іменем: «Бригадир», «Клінер-універсал».');
        yield TextField::new('roleRu', 'Роль ru')->hideOnIndex()
            ->setHelp('Порожньо - показується українська посада.');
        yield TextareaField::new('descUk', 'Опис uk')->hideOnIndex()
            ->setHelp('Кілька речень про досвід. Не обовʼязково: без опису картка теж виглядає нормально.');
        yield TextareaField::new('descRu', 'Опис ru')->hideOnIndex()
            ->setHelp('Порожньо - показується український опис.');
        yield from $this->imageFields('/media/service_team');
        yield IntegerField::new('position', 'Порядок')
            ->setHelp('Чим менше число, тим раніше людина в списку.');
        yield BooleanField::new('active', 'Активний')
            ->setHelp('Вимкнений співробітник зникає з сайту, але лишається тут.');
    }
}
