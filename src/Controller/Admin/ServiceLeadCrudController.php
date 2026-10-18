<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceLead;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        return $this->addCancelAction($actions)
            ->disable(Action::NEW);
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
