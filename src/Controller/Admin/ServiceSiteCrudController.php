<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceSite;
use App\Settings\AdminBranding;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ServiceSiteCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    public function __construct(private readonly AdminBranding $branding)
    {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceSite::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // сайт у БД один (звичайний прод: БД на сайт) — «Сайт», а не «Сервісні сайти» (власник 21.10.2026)
        $single = $this->branding->singleSite();

        return $crud
            ->setEntityLabelInSingular($single ? 'Сайт' : 'Сервісний сайт')
            ->setEntityLabelInPlural($single ? 'Сайт' : 'Сервісні сайти')
            // Сайти-бренди = конфігурація платформи: тільки адмін
            ->setEntityPermission('ROLE_ADMIN');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        // підказки в шапці таблиці беруться з setHelp() самих полів
        yield from $this->withHints($this->fieldList($pageName));
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Назва');
        yield TextField::new('brandNameUk', 'Коротка назва бренду uk')->hideOnIndex()
            ->setHelp('Для текстів на кшталт «Відгуки клієнтів про компанію …». Порожньо — параметр паку app.brand_name, далі «Назва». Загальне слово беріть у лапки: «Електрики».');
        yield TextField::new('brandNameRu', 'Коротка назва бренду ru')->hideOnIndex()
            ->setHelp('Порожньо — як uk.');
        yield TextField::new('slug', 'Slug')
            ->setHelp('Латинський ідентифікатор ніші; dev-хост = <slug>.localhost. Міняти обережно.');
        yield TextField::new('apexDomain', 'Апекс-домен')
            ->setHelp('Домен головного міста, напр. example.com.ua');
        yield TextField::new('schemaType', 'Schema.org тип')
            ->setHelp('Підтип LocalBusiness для JSON-LD: Electrician, Plumber, HouseCleaning…');
        yield BooleanField::new('active', 'Активний');
        yield TextField::new('heroImagePath', 'Ілюстрація хіро')->hideOnIndex()
            ->setHelp('Шлях від кореня сайту, напр. /themes/<ключ-паку>/hero.webp; порожньо = без ілюстрації');

        yield FormField::addFieldset('Сторінка «Про нас»');
        yield TextField::new('aboutTitleUk', 'Заголовок uk')->hideOnIndex();
        yield TextField::new('aboutTitleRu', 'Заголовок ru')->hideOnIndex();
        yield TextEditorField::new('aboutUk', 'Текст uk')->hideOnIndex();
        yield TextEditorField::new('aboutRu', 'Текст ru')->hideOnIndex();

        yield FormField::addFieldset('Юридичні сторінки');
        yield TextEditorField::new('privacyUk', 'Політика конфіденційності uk')->hideOnIndex()
            ->setHelp('Порожньо - на /privacy-policy/ показується типовий текст рушія');
        yield TextEditorField::new('privacyRu', 'Політика конфіденційності ru')->hideOnIndex();
        yield TextEditorField::new('termsUk', 'Умови надання послуг uk')->hideOnIndex()
            ->setHelp('Порожньо - на /terms-of-service/ показується типовий текст рушія');
        yield TextEditorField::new('termsRu', 'Умови надання послуг ru')->hideOnIndex();
    }
}
