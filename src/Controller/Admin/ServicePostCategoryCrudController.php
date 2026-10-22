<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServicePostCategory;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Рубрики блогу контентного сайту (22.10.2026): мовні поля — лише мовами розгортання (app.locales). */
#[IsGranted('ROLE_MODERATOR')]
class ServicePostCategoryCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** @param list<string> $locales */
    public function __construct(
        #[Autowire('%app.locales%')]
        private readonly array $locales = ['uk', 'ru'],
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ServicePostCategory::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Рубрика блогу')
            ->setEntityLabelInPlural('Рубрики блогу')
            ->setDefaultSort(['site' => 'ASC', 'position' => 'ASC'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        yield from $this->withHints($this->fieldList());
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('site', 'Сайт')->setHelp('Бренд, у блозі якого рубрика.');
        foreach ($this->locales as $i => $l) {
            yield FormField::addFieldset('Мова: ' . strtoupper($l));
            yield TextField::new('title' . ucfirst($l), 'Назва ' . $l)->setColumns(6)->setRequired($i === 0)->hideOnIndex($i > 0);
            yield TextField::new('slug' . ucfirst($l), 'Слаг ' . $l)->setColumns(6)->setRequired($i === 0)->hideOnIndex()
                ->setHelp('Адреса /<блог>/category/<слаг>/ — латиниця, цифри, дефіс. ⚠️ Після публікації краще не міняти.');
            yield TextEditorField::new('desc' . ucfirst($l), 'Вступ ' . $l)->hideOnIndex()->setColumns(12);
            yield TextField::new('metaTitle' . ucfirst($l), 'Meta title ' . $l)->hideOnIndex()->setColumns(6);
            yield TextareaField::new('metaDesc' . ucfirst($l), 'Meta description ' . $l)->hideOnIndex()->setColumns(6);
        }
        yield FormField::addFieldset('Показ');
        yield IntegerField::new('position', 'Порядок')->setHelp('Чим менше число, тим раніше рубрика в меню й списках.');
        yield BooleanField::new('active', 'Активна')->setHelp('Вимкнена рубрика зникає з сайту; статті лишаються.');
    }
}
