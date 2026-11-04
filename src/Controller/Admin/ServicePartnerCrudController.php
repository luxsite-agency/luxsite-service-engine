<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServicePartner;
use App\ServiceSite\Partners\PartnersConfig;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
final class ServicePartnerCrudController extends AbstractCrudController
{
    use CancelActionTrait;

    public function __construct(private readonly PartnersConfig $partners)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ServicePartner::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        if (!$this->partners->enabled) {
            throw $this->createNotFoundException();
        }

        return $crud->setEntityLabelInSingular('Компанія')->setEntityLabelInPlural('Компанії')
            ->setDefaultSort(['isOwn' => 'DESC', 'priority' => 'DESC', 'id' => 'ASC'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions)->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('city', 'Місто'))
            ->add(ChoiceFilter::new('status', 'Статус')->setChoices(self::statusChoices()));
    }

    /** @return array<string, string> */
    public static function statusChoices(): array
    {
        return ['Активна' => ServicePartner::STATUS_ACTIVE, 'Призупинена' => ServicePartner::STATUS_PAUSED, 'Пішла' => ServicePartner::STATUS_LEFT];
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('city', 'Місто');
        yield TextField::new('name', 'Назва');
        yield ChoiceField::new('status', 'Статус')->setChoices(self::statusChoices());
        yield BooleanField::new('isOwn', 'Власна компанія')->renderAsSwitch(false);
        yield IntegerField::new('priority', 'Пріоритет');
        yield IntegerField::new('dailyLimit', 'Добовий ліміт')->setHelp('Порожньо — без ліміту; 0 — без автоматичних передач.')->hideOnIndex();
        yield TextareaField::new('coverageZipsText', 'Покриття ZIP')->setHelp('По одному в рядку: 11968 або 119*. Порожньо — усе місто.')->hideOnIndex();
        yield TextField::new('notifyEmails', 'Email сповіщень')->setHelp('Кілька адрес через кому. Порожньо — без листа.');
        yield ChoiceField::new('model', 'Модель')->setChoices(['Спільна' => ServicePartner::MODEL_SHARED, 'Ексклюзивна' => ServicePartner::MODEL_EXCLUSIVE]);
        yield IntegerField::new('pricePerLead', 'Ціна заявки, центи')->hideOnIndex();
        yield TextareaField::new('licenseNotes', 'Ліцензії')->hideOnIndex();
        yield TextareaField::new('notes', 'Нотатки')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Створено')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('updatedAt', 'Оновлено')->hideOnForm()->hideOnIndex();
    }
}
