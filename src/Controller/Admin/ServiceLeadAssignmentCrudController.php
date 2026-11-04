<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceLeadAssignment;
use App\ServiceSite\Partners\PartnersConfig;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
final class ServiceLeadAssignmentCrudController extends AbstractCrudController
{
    use CancelActionTrait;

    public function __construct(private readonly PartnersConfig $partners)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceLeadAssignment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        if (!$this->partners->enabled) {
            throw $this->createNotFoundException();
        }

        return $crud->setEntityLabelInSingular('Передача заявки')->setEntityLabelInPlural('Передачі заявок')
            ->setDefaultSort(['assignedAt' => 'DESC'])->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions)->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::NEW);
    }

    /** @return array<string, string> */
    public static function statusChoices(): array
    {
        return ['Нова' => 'new', 'Прийнята' => 'accepted', 'Нема відповіді' => 'no_answer', 'Спам' => 'junk', 'Успішна' => 'won', 'Втрачена' => 'lost'];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('partner', 'Компанія'))
            ->add(ChoiceFilter::new('status', 'Статус')->setChoices(self::statusChoices()))
            ->add(EntityFilter::new('lead', 'Заявка'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('lead', 'Заявка')->hideOnForm();
        yield AssociationField::new('partner', 'Компанія')->hideOnForm();
        yield IntegerField::new('position', 'Позиція')->hideOnForm();
        yield ChoiceField::new('status', 'Статус')->setChoices(self::statusChoices());
        yield DateTimeField::new('assignedAt', 'Передано')->hideOnForm();
        yield DateTimeField::new('deliveredAt', 'Лист надіслано')->hideOnForm();
        yield IntegerField::new('price', 'Ціна, центи')->hideOnForm();
        yield DateTimeField::new('statusAt', 'Статус змінено')->onlyOnDetail();
        yield TextField::new('statusNote', 'Примітка статусу')->hideOnIndex();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->stampStatus($entityManager, $entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->stampStatus($entityManager, $entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function stampStatus(EntityManagerInterface $em, ServiceLeadAssignment $assignment): void
    {
        $original = $em->getUnitOfWork()->getOriginalEntityData($assignment);
        if (($original['status'] ?? ServiceLeadAssignment::STATUS_NEW) !== $assignment->getStatus()) {
            $assignment->setStatusAt(new \DateTimeImmutable());
        }
    }
}
