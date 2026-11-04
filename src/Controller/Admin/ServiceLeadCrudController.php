<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceLead;
use App\Entity\ServicePartner;
use App\Repository\ServicePartnerRepository;
use App\ServiceSite\LeadConsent;
use App\ServiceSite\LeadFields;
use App\ServiceSite\Partners\LeadRouter;
use App\ServiceSite\Partners\PartnersConfig;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Черга заявок: read-mostly — створюються фронт-формою, в адмінці лише
 * перегляд і позначка «оброблено».
 */
#[IsGranted('ROLE_MODERATOR')]
class ServiceLeadCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: телефон - головне в заявці */
    private const INDEX_PRIMARY = 'phone';

    public function __construct(
        private readonly LeadFields $leadFields,
        private readonly LeadConsent $leadConsent,
        private readonly PartnersConfig $partners,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceLead::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Заявка')
            ->setEntityLabelInPlural('Заявки')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureActions(Actions $actions): Actions
    {
        if ($this->partners->enabled) {
            $actions->add(Crud::PAGE_DETAIL, Action::new('assignPartner', 'Передати компанії', 'fa fa-share')
                ->linkToCrudAction('assignPartner'));
            $actions->setPermission('assignPartner', 'ROLE_MODERATOR');
        }
        // перегляд заявки (detail) — там файли посиланнями; редагування лишається для позначки «Оброблено»
        return $this->addCancelAction($actions)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        // підказки в шапці таблиці беруться з setHelp() самих полів
        yield from $this->withHints($this->primaryFirst($this->fieldList($pageName), $pageName));
    }

    public function configureFilters(Filters $filters): Filters
    {
        if ($this->partners->enabled) {
            $filters->add(ChoiceFilter::new('routing', 'Маршрутизація')->setChoices(['Передано' => ServiceLead::ROUTING_ASSIGNED, 'Без виконавця' => ServiceLead::ROUTING_UNROUTED]));
        }

        return $filters;
    }

    #[AdminRoute(path: '/{entityId}/assign-partner', name: 'assign_partner', options: ['methods' => ['GET', 'POST']])]
    public function assignPartner(AdminContext $context, Request $request, ServicePartnerRepository $partners, LeadRouter $router): Response
    {
        if (!$this->partners->enabled) {
            throw $this->createNotFoundException();
        }
        $lead = $context->getEntity()->getInstance();
        if (!$lead instanceof ServiceLead || $lead->getCity() === null) {
            throw $this->createNotFoundException();
        }
        $form = $this->createFormBuilder(null, ['csrf_token_id' => 'partner-assign-' . $lead->getId()])
            ->add('partner', EntityType::class, ['class' => ServicePartner::class, 'label' => 'Компанія', 'placeholder' => 'Оберіть компанію',
                'choices' => $partners->manualForCity($lead->getCity()), 'constraints' => [new NotNull()]])->getForm();
        $form->handleRequest($request);
        $detailUrl = $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)
            ->setAction(Action::DETAIL)->setEntityId($lead->getId())->generateUrl();
        if ($form->isSubmitted() && $form->isValid()) {
            $router->assignManually($lead, $form->get('partner')->getData());
            $this->addFlash('success', 'Заявку передано компанії.');

            return $this->redirect($detailUrl);
        }

        return $this->render('admin/lead/assign_partner.html.twig', ['form' => $form->createView(), 'lead' => $lead, 'detail_url' => $detailUrl]);
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('city', 'Місто');
        yield TextField::new('phone', 'Телефон')
            ->setHelp('Як його ввела людина. Саме за цим номером CRM (Pipedrive) шукає дублі контактів.');
        if ($this->partners->enabled) {
            yield TextField::new('zipFrom', 'Звідки ZIP')->hideOnForm();
            yield TextField::new('routing', 'Маршрутизація')->hideOnForm();
            yield TextField::new('zipTo', 'Куди ZIP')->onlyOnDetail();
            yield Field::new('assignments', 'Передано')->onlyOnDetail()->setTemplatePath('admin/lead/field/assignments.html.twig');
        }
        yield TextField::new('name', 'Ім\'я')
            ->setHelp('Необовʼязкове поле у формі, тому часто порожнє.');
        if ($this->leadFields->enabled()) {
            yield TextField::new('detailsShort', 'Поля форми')->onlyOnIndex()
                ->setHelp('Додаткові поля форми (послуга, лікар, дата тощо). Повністю, з підписами, - у «Повідомленні».');
        }
        yield TextareaField::new('message', 'Повідомлення')->hideOnIndex()
            ->setHelp(
                'Текст із форми або розрахунок калькулятора: тип, площа, коефіцієнти, опції, сума. Суму '
                . 'рахує сервер, з форми вона не приймається. Додаткові поля форми (якщо пак їх задає) - '
                . 'окремими рядками «Поле: значення» в кінці.'
            );
        yield TextField::new('service', 'Послуга')
            ->setHelp('Послуга з кнопки «Замовити», з якої надіслали форму (поле service). Порожньо — форма без послуги.');
        yield TextField::new('context', 'Звідки')->hideOnIndex()
            ->setHelp('Яка саме форма чи блок сторінки (поле context): шапка, картка ціни, доставка…');
        yield IntegerField::new('filesCount', 'Файли')->onlyOnIndex()
            ->setHelp('Скільки файлів людина додала до заявки (app.lead_files).');
        yield Field::new('files', 'Файли')->onlyOnDetail()->setTemplatePath('admin/lead/field/files.html.twig')
            ->setHelp('Фото чи PDF, які людина додала до заявки (app.lead_files). Лежать поза сайтом, відкриваються лише після входу; '
                . 'видаляються разом із заявкою.');
        yield TextField::new('pageUrl', 'Сторінка')->hideOnIndex()
            ->setHelp('Сторінка, з якої надіслали заявку. Показує, що саме людина читала перед зверненням.');
        if ($this->leadConsent->enabled()) {
            yield TextareaField::new('consentText', 'Текст згоди')->onlyOnDetail();
            yield TextField::new('consentIp', 'IP згоди')->onlyOnDetail();
            yield DateTimeField::new('consentAt', 'Згода на обробку ПД')->hideOnIndex()->setFormTypeOption('disabled', true)
                ->setHelp('Коли людина поставила галочку згоди на обробку персональних даних у формі.');
        }
        yield TextField::new('locale', 'Мова')->hideOnIndex()
            ->setHelp('Мова сторінки: uk або ru.');
        yield TextField::new('utmSource', 'Джерело')->hideOnIndex()
            ->setHelp(
                'Звідки прийшов візит: google, chatgpt.com, facebook. Береться з першого заходу на '
                . 'сайт, а не з останнього.'
            );
        yield TextField::new('utmMedium', 'Канал')->hideOnIndex()
            ->setHelp('Тип трафіку: organic, cpc, ai-assistant.');
        yield TextField::new('utmCampaign', 'Кампанія')->hideOnIndex()
            ->setHelp('Назва рекламної кампанії, якщо посилання було з міткою.');
        yield TextField::new('utmContent', 'Оголошення')->hideOnIndex()
            ->setHelp('Конкретне оголошення чи банер усередині кампанії.');
        yield TextField::new('utmTerm', 'Ключ')->hideOnIndex()
            ->setHelp('Ключове слово, за яким показалось оголошення.');
        yield TextField::new('clickId', 'ID кліку')->hideOnIndex()
            ->setHelp('gclid від Google Ads. Потрібен, щоб звести заявку з кліком у рекламному кабінеті.');
        yield TextField::new('referrer', 'Звідки перейшли')->hideOnIndex()
            ->setHelp('Сторінка, з якої людина перейшла до нас уперше.');
        yield TextField::new('landingUrl', 'Сторінка входу')->hideOnIndex()
            ->setHelp('Перша сторінка нашого сайту в цьому візиті.');
        yield TextField::new('crmExternalId', 'Ід у CRM')->hideOnIndex()->setFormTypeOption('disabled', true)
            ->setHelp(
                'Номер запису в CRM сайту (лід Pipedrive, угода Bitrix24). Заповнений - заявка вже пішла в CRM. '
                . 'Порожній - ще в черзі, крон спробує ще раз протягом 10 хвилин (якщо CRM підключено).'
            );
        yield TextField::new('crmSystem', 'CRM')->hideOnIndex()->setFormTypeOption('disabled', true)
            ->setHelp('У яку CRM пішла заявка: pipedrive, bitrix24. Порожньо - ще не відправлялась.');
        yield DateTimeField::new('crmSyncedAt', 'У CRM')->hideOnIndex()->setFormTypeOption('disabled', true)
            ->setHelp('Коли саме заявка пішла в CRM.');
        yield TextField::new('crmError', 'Помилка CRM')->hideOnIndex()->setFormTypeOption('disabled', true)
            ->setHelp('Текст останньої помилки відправки. Порожньо - помилок не було.');
        yield DateTimeField::new('createdAt', 'Створено')->onlyOnIndex()
            ->setHelp('Час у часовому поясі сервера.');
        yield BooleanField::new('processed', 'Оброблено')
            ->setHelp(
                'Службова позначка: оброблені заявки крон більше не чіпає, навіть якщо їх не вдалося '
                . 'відправити.'
            );
    }
}
