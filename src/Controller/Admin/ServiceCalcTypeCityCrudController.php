<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCalcTypeCity;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MODERATOR')]
class ServiceCalcTypeCityCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: тип прибирання, а місто - другим */
    private const INDEX_PRIMARY = 'type';

    public static function getEntityFqcn(): string
    {
        return ServiceCalcTypeCity::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Ціна типу в місті')
            ->setEntityLabelInPlural('Калькулятор: ціни по містах')
            ->setDefaultSort(['city' => 'ASC', 'type' => 'ASC'])
            ->setHelp('index', 'Тут задається ціна калькулятора для окремого міста.
                Базова ставка живе в розділі «Типи прибирання» і діє всюди, а рядок тут
                її перебиває - тільки для вказаного міста й типу. Порожнє поле означає
                «лишити як базову»: можна змінити ціну за м², не чіпаючи мінімальний чек.
                Колонка «Базова ставка» показує, від чого йде відхилення.')
            ->setHelp('new', 'Оберіть місто і тип прибирання, далі впишіть ціну саме для них.
                Усе, що лишите порожнім, візьметься з базової ставки типу.')
            ->setHelp('edit', 'Порожнє поле = береться базова ставка з «Типів прибирання».')
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('city', 'Місто'))
            ->add(EntityFilter::new('type', 'Тип прибирання'));
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
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('city', 'Місто')
            ->setColumns(6)
            ->setHtmlAttributes($this->hint('Для якого міського сайту діє ця ціна. Міста без свого рядка платять за базовою.'));
        yield AssociationField::new('type', 'Тип прибирання')
            ->setColumns(6)
            ->setHtmlAttributes($this->hint('Який саме тип дорожчає чи дешевшає в цьому місті. На кожен тип - окремий рядок.'));
        yield NumberField::new('pricePerSqm', 'Ціна за м² в цьому місті, грн')
            ->setNumDecimals(2)->setColumns(6)
            ->setHelp('Скільки коштує один квадратний метр. Порожньо - береться базова ставка типу');
        yield NumberField::new('minPrice', 'Мінімальний чек, грн')->setNumDecimals(2)->setColumns(6)
            ->setHelp('Нижче цієї суми розрахунок не опускається, навіть на малій площі');
        yield TextField::new('baseSummary', 'Базова ставка')->onlyOnIndex()
            ->setHtmlAttributes($this->hint(
                'Ціна з розділу «Типи: базові ціни». Показана тут, щоб було видно, від чого '
                . 'відхиляється міська, і не доводилось відкривати сусідній розділ.'
            ));
        yield TextField::new('deviation', 'Відхилення')->onlyOnIndex()
            ->setHtmlAttributes($this->hint(
                'Наскільки міська ціна відрізняється від базової. «як базова» означає, що поле ціни '
                . 'порожнє і діє загальна ставка.'
            ));
    }
}
