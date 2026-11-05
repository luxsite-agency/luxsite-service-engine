<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Partner;

use App\Entity\ServiceLeadAssignment;
use App\Security\PartnerCabinetAccess;
use App\ServiceSite\Partners\PartnersConfig;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PartnerLeadCrudController extends AbstractCrudController
{
    public function __construct(private readonly PartnerCabinetAccess $access, private readonly PartnersConfig $config)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceLeadAssignment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $this->access->partner();

        return $crud->setEntityLabelInSingular('lead')->setEntityLabelInPlural('leads')
            ->setDefaultSort(['assignedAt' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['lead.name', 'lead.phone', 'lead.zipFrom', 'lead.zipTo'])
            ->setFormOptions(['validation_groups' => ['Default', 'partner_cabinet']]);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE);
    }

    /** @return array<string, string> */
    public static function statusChoices(bool $editable = false): array
    {
        $statuses = $editable ? array_diff(ServiceLeadAssignment::STATUSES, [ServiceLeadAssignment::STATUS_NEW]) : ServiceLeadAssignment::STATUSES;
        $choices = [];
        foreach ($statuses as $status) {
            $choices['status.' . $status] = $status;
        }

        return $choices;
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('status', 'status_label')->setChoices(self::statusChoices()))
            ->add(DateTimeFilter::new('assignedAt', 'assigned_at'));
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.partner = :cabinetPartner')->setParameter('cabinetPartner', $this->access->partner());
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $this->access->assignment($context->getEntity()->getInstance());

        return parent::detail($context);
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        $this->access->assignment($context->getEntity()->getInstance());

        return parent::edit($context);
    }

    public function autocomplete(AdminContext $context): JsonResponse
    {
        throw $this->createNotFoundException();
    }

    public function configureFields(string $pageName): iterable
    {
        $editing = $pageName === Crud::PAGE_EDIT;
        yield IdField::new('id', 'assignment_id')->hideOnForm();
        yield DateTimeField::new('assignedAt', 'assigned_at')->setTimezone($this->config->timezone->getName())->hideOnForm();
        yield TextField::new('lead.zipFrom', 'zip_from')->hideOnForm();
        yield TextField::new('lead.zipTo', 'zip_to')->hideOnForm();
        yield TextField::new('lead.name', 'customer_name')->hideOnForm();
        yield TextField::new('lead.phone', 'phone')->setTemplatePath('partner/field/phone.html.twig')->hideOnForm();
        yield ChoiceField::new('status', 'status_label')->setChoices(self::statusChoices($editing))
            ->renderAsBadges(['new' => 'warning', 'accepted' => 'info', 'no_answer' => 'secondary', 'junk' => 'danger', 'won' => 'success', 'lost' => 'secondary']);
        yield MoneyField::new('price', 'price')->setCurrency('USD')->hideOnForm();
        yield TextareaField::new('statusNote', 'status_note')->hideOnIndex();
        yield DateTimeField::new('statusAt', 'status_at')->setTimezone($this->config->timezone->getName())->onlyOnDetail();
        yield TextareaField::new('lead.message', 'message')->onlyOnDetail();
        yield TextField::new('lead.detailsShort', 'request_fields')->setTemplatePath('partner/field/details.html.twig')->onlyOnDetail();
        yield TextareaField::new('lead.consentText', 'consent_text')->onlyOnDetail();
        yield DateTimeField::new('lead.consentAt', 'consent_at')->setTimezone($this->config->timezone->getName())->onlyOnDetail();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $assignment = $this->access->assignment($entityInstance);
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($assignment);
        if (($original['status'] ?? null) !== $assignment->getStatus()) {
            $assignment->setStatusAt(new \DateTimeImmutable());
        }
        parent::updateEntity($entityManager, $assignment);
    }
}
