<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceLead;
use App\ServiceSite\LeadDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NullFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Черга заявок: read-mostly — створюються фронт-формою, в адмінці лише перегляд і позначка «оброблено».
 * Спам (LeadSpamGuard, spam_reason) лежить тут же з бейджем і фільтром «Спам»; дія «Не спам» знімає позначку й відправляє
 * заявку листом і в CRM тим самим шляхом, що звичайну (LeadDispatcher).
 */
#[IsGranted('ROLE_MODERATOR')]
class ServiceLeadCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: телефон - головне в заявці */
    private const INDEX_PRIMARY = 'phone';

    private const NOT_SPAM_CSRF = 'lead-not-spam';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeadDispatcher $dispatcher,
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
        // «Не спам»: лише в записів зі spam_reason; посилання з CSRF-токеном (дія змінює дані й шле лист/CRM)
        $notSpam = Action::new('notSpam', 'Не спам', 'fa fa-check')
            ->linkToUrl(fn (ServiceLead $lead): string => $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()
                ->setController(self::class)->setAction('notSpam')->setEntityId($lead->getId())
                ->set('_t', $this->container->get('security.csrf.token_manager')->getToken(self::NOT_SPAM_CSRF)->getValue())->generateUrl())
            ->displayIf(static fn (ServiceLead $lead): bool => $lead->isSpam())
            ->setHtmlAttributes(['title' => 'Зняти позначку спаму й відправити заявку менеджерам (лист, CRM)']);

        // перегляд заявки (detail) — там файли посиланнями; редагування лишається для позначки «Оброблено»
        return $this->addCancelAction($actions)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $notSpam)
            ->add(Crud::PAGE_DETAIL, $notSpam)
            ->disable(Action::NEW);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('city', 'Місто'))
            ->add(NullFilter::new('spamReason', 'Спам')->setChoiceLabels('Ні — звичайні заявки', 'Так — лише спам'))
            ->add(BooleanFilter::new('processed', 'Оброблено'));
    }

    /**
     * «Не спам»: хибне спрацювання захисту. Знімає spam_reason і відправляє заявку листом і в CRM тим самим шляхом, що
     * звичайну (LeadDispatcher); app:crm:sync далі теж її бачить.
     *
     * @param AdminContext<ServiceLead> $context
     */
    #[AdminRoute(path: '/{entityId}/not-spam', name: 'not_spam')]
    public function notSpam(AdminContext $context, Request $request): Response
    {
        $lead = $context->getEntity()->getInstance();
        if (!$lead instanceof ServiceLead) {
            throw $this->createNotFoundException();
        }
        $back = $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl();
        if (!$this->isCsrfTokenValid(self::NOT_SPAM_CSRF, (string) $request->query->get('_t', ''))) {
            $this->addFlash('danger', 'Недійсний CSRF-токен, спробуйте ще раз');

            return $this->redirect($back);
        }
        if (!$lead->isSpam()) {
            $this->addFlash('info', sprintf('Заявка #%d і так не спам.', $lead->getId()));

            return $this->redirect($back);
        }

        $reason = (string) $lead->getSpamReason();
        $lead->setSpamReason(null);
        $this->em->flush();
        $city = $lead->getCity();
        $inCrm = $this->dispatcher->dispatch($lead, 'Заявка', $city?->getHost() ?? '');
        $this->addFlash('success', sprintf(
            'Заявка #%d (%s) більше не спам (було: %s): лист менеджерам надіслано%s.',
            $lead->getId(),
            htmlspecialchars($lead->getPhone(), \ENT_QUOTES),
            htmlspecialchars($reason, \ENT_QUOTES),
            $inCrm ? ', у CRM відправлено' : ($lead->getCrmError() !== null ? ', CRM: ' . htmlspecialchars((string) $lead->getCrmError(), \ENT_QUOTES) : ''),
        ));

        return $this->redirect($back);
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
        yield AssociationField::new('city', 'Місто');
        yield TextField::new('phone', 'Телефон')
            ->setHelp('Як його ввела людина. Саме за цим номером CRM (Pipedrive) шукає дублі контактів.');
        yield TextField::new('name', 'Ім\'я')
            ->setHelp('Необовʼязкове поле у формі, тому часто порожнє.');
        yield TextareaField::new('message', 'Повідомлення')->hideOnIndex()
            ->setHelp(
                'Текст із форми або розрахунок калькулятора: тип, площа, коефіцієнти, опції, сума. Суму '
                . 'рахує сервер, з форми вона не приймається.'
            );
        yield TextField::new('service', 'Послуга')
            ->setHelp('Послуга з кнопки «Замовити», з якої надіслали форму (поле service). Порожньо — форма без послуги.');
        yield TextField::new('spamReason', 'Спам')->setTemplatePath('admin/lead/field/spam.html.twig')->setFormTypeOption('disabled', true)
            ->setHelp(
                'Захист форм визнав заявку спамом: причина — honeypot, too-fast (швидше за 3 с), time-token (підроблена мітка), '
                . 'junk/url (SQL, теги, посилання), latin-only (без кирилиці), phone (короткий номер чи чужа країна), turnstile. '
                . 'Лист і CRM не надсилались, конверсія не рахувалась. Хибне спрацювання — дія «Не спам»: відправить як звичайну.'
            );
        yield TextField::new('context', 'Звідки')->hideOnIndex()
            ->setHelp('Яка саме форма чи блок сторінки (поле context): шапка, картка ціни, доставка…');
        yield IntegerField::new('filesCount', 'Файли')->onlyOnIndex()
            ->setHelp('Скільки файлів людина додала до заявки (app.lead_files).');
        yield Field::new('files', 'Файли')->onlyOnDetail()->setTemplatePath('admin/lead/field/files.html.twig')
            ->setHelp('Фото чи PDF, які людина додала до заявки (app.lead_files). Лежать поза сайтом, відкриваються лише після входу; '
                . 'видаляються разом із заявкою.');
        yield TextField::new('pageUrl', 'Сторінка')->hideOnIndex()
            ->setHelp('Сторінка, з якої надіслали заявку. Показує, що саме людина читала перед зверненням.');
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
