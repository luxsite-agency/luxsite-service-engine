<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceLeadAssignment;
use App\ServiceSite\Partners\PartnersConfig;
use App\ServiceSite\Partners\PartnerNotifier;
use App\Controller\Admin\Filter\UndeliveredFilter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        return $this->addCancelAction($actions)->add(Crud::PAGE_INDEX, Action::DETAIL)->disable(Action::NEW)
            ->add(Crud::PAGE_DETAIL, Action::new('redeliver', 'Надіслати ще раз', 'fa fa-repeat')->linkToCrudAction('redeliver'))
            ->setPermission('redeliver', 'ROLE_MODERATOR');
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
            ->add(EntityFilter::new('lead', 'Заявка'))
            ->add(UndeliveredFilter::new('undelivered', 'Не доставлено'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('lead', 'Заявка')->hideOnForm();
        yield AssociationField::new('partner', 'Компанія')->hideOnForm();
        yield IntegerField::new('position', 'Позиція')->hideOnForm();
        yield ChoiceField::new('status', 'Статус')->setChoices(self::statusChoices());
        yield DateTimeField::new('assignedAt', 'Передано')->hideOnForm();
        yield DateTimeField::new('deliveredAt', 'Email')->hideOnForm();
        yield DateTimeField::new('webhookDeliveredAt', 'Вебхук')->hideOnForm();
        yield IntegerField::new('attempts', 'Спроб')->hideOnForm();
        yield DateTimeField::new('nextAttemptAt', 'Наступна спроба')->hideOnForm();
        yield TextField::new('lastError', 'Помилка')->onlyOnDetail();
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

    #[AdminRoute(path: '/{entityId}/redeliver', name: 'redeliver', options: ['methods' => ['GET', 'POST']])]
    public function redeliver(AdminContext $context, Request $request, PartnerNotifier $notifier): Response
    {
        if (!$this->partners->enabled) {
            throw $this->createNotFoundException();
        }
        $assignment = $context->getEntity()->getInstance();
        if (!$assignment instanceof ServiceLeadAssignment) {
            throw $this->createNotFoundException();
        }
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'partner-redeliver-' . $assignment->getId()])->getForm();
        $form->handleRequest($request);
        $url = $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)
            ->setAction(Action::DETAIL)->setEntityId($assignment->getId())->generateUrl();
        if ($form->isSubmitted() && $form->isValid()) {
            $assignment->setAttempts(0);
            $notifier->notify($assignment);
            $ok = $assignment->isFullyDelivered() && $assignment->getLastError() === null;
            $this->addFlash($ok ? 'success' : 'danger', $ok ? 'Усі канали доставлено.'
                : htmlspecialchars($assignment->getLastError() ?? 'Доставка не вдалася.', \ENT_QUOTES));

            return $this->redirect($url);
        }

        return $this->render('admin/partners/delivery_action.html.twig', ['form' => $form->createView(),
            'title' => 'Надіслати ще раз', 'description' => (string) $assignment, 'detail_url' => $url]);
    }
}
