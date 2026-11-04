<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServicePartner;
use App\ServiceSite\Partners\PartnersConfig;
use App\ServiceSite\Partners\PartnerNotifier;
use App\Settings\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    public function __construct(private readonly PartnersConfig $partners, private readonly SecretBox $box)
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
        return $this->addCancelAction($actions)->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, Action::new('pingWebhook', 'Перевірити вебхук', 'fa fa-paper-plane')
                ->linkToCrudAction('pingWebhook')->displayIf(static fn (ServicePartner $p): bool => $p->getWebhookUrl() !== null))
            ->setPermission('pingWebhook', 'ROLE_MODERATOR');
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
        yield TextField::new('webhookUrl', 'URL вебхука')->setRequired(false)
            ->setHelp('Лише https://. Отримує POST JSON; за наявності секрету — з підписом X-Lead-Signature.');
        yield TextField::new('webhookSecretMask', 'Секрет підпису')->hideOnForm()->setSortable(false);
        yield TextField::new('webhookSecretInput', 'Секрет підпису')->onlyOnForms()->setFormType(PasswordType::class)
            ->setRequired(false)->setDisabled(!$this->box->isAvailable())
            ->setFormTypeOptions(['mapped' => false, 'always_empty' => true, 'attr' => ['autocomplete' => 'new-password']])
            ->setHelp($this->box->isAvailable() ? 'Порожньо — не змінювати збережений секрет.'
                : 'SETTINGS_SECRET_KEY недоступний: секрет не можна зберегти. Без секрету вебхук працює без підпису; збережений секрет потребує чинного ключа.');
        yield BooleanField::new('clearWebhookSecret', 'Очистити секрет')->onlyOnForms()->renderAsSwitch(false)
            ->setFormTypeOptions(['mapped' => false, 'required' => false]);
        yield ChoiceField::new('model', 'Модель')->setChoices(['Спільна' => ServicePartner::MODEL_SHARED, 'Ексклюзивна' => ServicePartner::MODEL_EXCLUSIVE]);
        yield IntegerField::new('pricePerLead', 'Ціна заявки, центи')->hideOnIndex();
        yield TextareaField::new('licenseNotes', 'Ліцензії')->hideOnIndex();
        yield TextareaField::new('notes', 'Нотатки')->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Створено')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('updatedAt', 'Оновлено')->hideOnForm()->hideOnIndex();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->saveSecret($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->saveSecret($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function saveSecret(ServicePartner $partner): void
    {
        $data = $this->getContext()?->getRequest()->request->all('ServicePartner') ?? [];
        if (filter_var($data['clearWebhookSecret'] ?? false, \FILTER_VALIDATE_BOOL)) {
            $partner->setWebhookSecret(null);
        } elseif ($this->box->isAvailable() && ($data['webhookSecretInput'] ?? '') !== '') {
            $partner->setWebhookSecret($this->box->encrypt($data['webhookSecretInput']));
        }
    }

    #[AdminRoute(path: '/{entityId}/ping-webhook', name: 'ping_webhook', options: ['methods' => ['GET', 'POST']])]
    public function pingWebhook(AdminContext $context, Request $request, PartnerNotifier $notifier): Response
    {
        if (!$this->partners->enabled) {
            throw $this->createNotFoundException();
        }
        $partner = $context->getEntity()->getInstance();
        if (!$partner instanceof ServicePartner) {
            throw $this->createNotFoundException();
        }
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'partner-ping-' . $partner->getId()])->getForm();
        $form->handleRequest($request);
        $url = $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)
            ->setAction(Action::DETAIL)->setEntityId($partner->getId())->generateUrl();
        if ($form->isSubmitted() && $form->isValid()) {
            $error = $notifier->ping($partner);
            $this->addFlash($error === null ? 'success' : 'danger', $error === null ? 'Вебхук успішно перевірено.' : htmlspecialchars($error, \ENT_QUOTES));

            return $this->redirect($url);
        }

        return $this->render('admin/partners/delivery_action.html.twig', ['form' => $form->createView(),
            'title' => 'Перевірити вебхук', 'description' => $partner->getName(), 'detail_url' => $url]);
    }
}
