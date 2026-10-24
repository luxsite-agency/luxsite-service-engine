<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServicePostTag;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Теги статей контентного сайту (24.10.2026): новинні бренди — люди з прямою мовою, їх створює приймач content-studio.
 * Мовні поля — лише мовами розгортання (app.locales).
 */
#[IsGranted('ROLE_MODERATOR')]
class ServicePostTagCrudController extends AbstractCrudController
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
        return ServicePostTag::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Тег статей')
            ->setEntityLabelInPlural('Теги статей')
            ->setDefaultSort(['site' => 'ASC', 'id' => 'DESC'])
            ->setSearchFields(['titleUk', 'titleRu', 'titleEn', 'slugUk', 'slugRu', 'slugEn'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('site', 'Сайт'))->add(BooleanFilter::new('active', 'Активний'));
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
        yield AssociationField::new('site', 'Сайт')->setHelp('Бренд, у статтях якого тег.');
        foreach ($this->locales as $i => $l) {
            yield FormField::addFieldset('Мова: ' . strtoupper($l));
            $title = TextField::new('title' . ucfirst($l), 'Назва ' . $l)->setColumns(6)->setRequired($i === 0)
                ->setHelp('Ім’я людини чи тема, як у рядку тегів під статтею.');
            $slug = TextField::new('slug' . ucfirst($l), 'Слаг ' . $l)->setColumns(6)->setRequired($i === 0)
                ->setHelp('Адреса /<блог>/tag/<слаг>/ — латиниця, цифри, дефіс. Студія знаходить тег саме за слагом. ⚠️ Після публікації краще не міняти.');
            // у списку — лише перша мова розгортання
            yield $i > 0 ? $title->hideOnIndex() : $title;
            yield $i > 0 ? $slug->hideOnIndex() : $slug;
        }
        yield FormField::addFieldset('Показ');
        yield BooleanField::new('active', 'Активний')->setHelp('Вимкнений тег зникає зі статей і його сторінка віддає 404; зв’язки зі статтями лишаються.');
    }
}
