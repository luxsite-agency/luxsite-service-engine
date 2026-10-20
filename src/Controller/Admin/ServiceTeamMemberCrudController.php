<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceTeamMember;
use App\Form\ExtraFieldsType;
use App\ServiceSite\CustomFields;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
class ServiceTeamMemberCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;
    use ImageFieldsTrait;

    /** У списку виноситься наперед: імʼя, а не місто */
    private const INDEX_PRIMARY = 'nameUk';

    public function __construct(
        private readonly CustomFields $customFields,
        /** app.team_prefix: '' = сторінок команди нема (поля слагу/SEO в адмінці все одно є — на майбутнє) */
        #[Autowire('%app.team_prefix%')]
        private readonly string $teamPrefix = '',
    ) {
    }

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
            ->setSearchFields(['nameUk', 'nameRu', 'roleUk', 'slugUk', 'slugRu'])
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
        $pages = $this->teamPrefix !== '';
        yield IdField::new('id')->onlyOnIndex();

        yield FormField::addTab('Основне', 'fa fa-user');
        yield AssociationField::new('city', 'Основне місто')
            ->setHelp($pages
                ? 'Філія, де людина працює постійно: тут її сторінка головна (canonical), сюди веде пошук.'
                : 'Людину видно лише на сайті цього міста, у блоці «Команда».');
        yield AssociationField::new('cities', 'Також працює в містах')->hideOnIndex()
            ->setFormTypeOption('by_reference', false)
            ->setHelp('Інші міста сайту, де людина теж працює (лікар приймає у двох філіях): там вона є в блоці й списку команди, '
                . 'а її сторінка там має canonical на основне місто. Порожньо = лише основне місто.');
        yield TextField::new('nameUk', 'Ім\'я uk')
            ->setHelp('Імʼя, як його бачить відвідувач. Прізвище зазвичай не пишемо (лікарям — повне ПІБ).');
        yield TextField::new('nameRu', 'Ім\'я ru')->hideOnIndex()
            ->setHelp('Порожньо - показується українське імʼя.');
        yield TextField::new('roleUk', 'Роль uk')
            ->setHelp('Посада під іменем: «Бригадир», «Клінер-універсал», «Дитячий невролог».');
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

        yield FormField::addTab('Послуги', 'fa fa-list-check');
        yield AssociationField::new('items', 'Послуги')->hideOnIndex()
            ->setFormTypeOption('by_reference', false)
            ->setHelp('Що робить людина: на сторінці послуги зʼявиться блок «Фахівці» з нею (у містах, де вона працює), '
                . 'на її сторінці — список цих послуг.');

        if ($this->customFields->has('team')) {
            yield FormField::addTab('Додаткові поля', 'fa fa-list');
            yield Field::new('extra', 'Додаткові поля')->hideOnIndex()->setFormType(ExtraFieldsType::class)
                ->setFormTypeOption('record_type', 'team')
                ->setHelp('Набір полів задає пак сайту (app.custom_fields.team: стаж, освіта, спеціалізація…). Показуються на сторінці людини.');
        }

        yield FormField::addTab('Сторінка і SEO', 'fa fa-magnifying-glass');
        yield TextField::new('slugUk', 'Слаг uk')->hideOnIndex()->setColumns(6)
            ->setHelp($pages
                ? 'Адреса сторінки: /' . $this->teamPrefix . '/<слаг>/. Порожньо = без своєї сторінки (лише в блоках і списку).'
                : 'Сторінки команди на цьому сайті вимкнені (app.team_prefix); слаг знадобиться, коли їх увімкнуть.');
        yield TextField::new('slugRu', 'Слаг ru')->hideOnIndex()->setColumns(6)
            ->setHelp('Порожньо = український слаг.');
        yield TextField::new('metaTitleUk', 'Meta title uk')->hideOnIndex()->setColumns(6)
            ->setHelp('До ~60 символів. Порожньо = «Імʼя - роль - сайт».');
        yield TextField::new('metaTitleRu', 'Meta title ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('metaDescUk', 'Meta description uk')->hideOnIndex()->setColumns(6)
            ->setHelp('~150-160 символів. Порожньо = початок опису.');
        yield TextareaField::new('metaDescRu', 'Meta description ru')->hideOnIndex()->setColumns(6);
    }
}
