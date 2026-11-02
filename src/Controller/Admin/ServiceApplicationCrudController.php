<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceApplication;
use App\ServiceSite\Applications;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Анкети» (app.applications): анкета перекладача й інші форми не про послугу — окремо від заявок клієнтів, без CRM.
 * Нові не створюються з адмінки; змінити можна лише позначку «Оброблено». Видалення прибирає й файли анкети.
 * Пункт меню — лише коли в паку є хоч одна форма (DashboardController).
 */
#[IsGranted('ROLE_MODERATOR')]
class ServiceApplicationCrudController extends AbstractCrudController
{
    use CancelActionTrait;

    public function __construct(private readonly Applications $applications)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceApplication::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Анкета')
            ->setEntityLabelInPlural('Анкети')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['name', 'phone', 'email'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $forms = [];
        foreach ($this->applications->keys() as $key) {
            $forms[$this->applications->title($key, 'uk')] = $key;
        }

        return $filters
            ->add(ChoiceFilter::new('form', 'Форма')->setChoices($forms))
            ->add(BooleanFilter::new('processed', 'Оброблено'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield DateTimeField::new('createdAt', 'Надіслано')->hideOnForm();
        yield TextField::new('form', 'Форма')->setFormTypeOption('disabled', true)
            ->formatValue(fn ($v): string => $this->applications->has((string) $v) ? $this->applications->title((string) $v, 'uk') : (string) $v);
        yield AssociationField::new('city', 'Місто')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield TextField::new('name', "Ім'я")->setFormTypeOption('disabled', true);
        yield TextField::new('phone', 'Телефон')->setFormTypeOption('disabled', true);
        yield TextField::new('email', 'Email')->setFormTypeOption('disabled', true);
        yield IntegerField::new('filesCount', 'Файли')->onlyOnIndex();
        yield Field::new('data', 'Анкета')->onlyOnDetail()->setTemplatePath('admin/application/field/data.html.twig');
        yield Field::new('files', 'Файли')->onlyOnDetail()->setTemplatePath('admin/lead/field/files.html.twig');
        yield BooleanField::new('consent', 'Згода на обробку даних')->hideOnIndex()->setFormTypeOption('disabled', true)->renderAsSwitch(false);
        yield TextField::new('pageUrl', 'Сторінка')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield BooleanField::new('processed', 'Оброблено')
            ->setHelp('Службова позначка: анкету розглянуто.');
    }
}
