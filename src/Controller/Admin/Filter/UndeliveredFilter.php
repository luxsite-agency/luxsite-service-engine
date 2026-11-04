<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin\Filter;

use App\ServiceSite\Partners\PartnerNotifier;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\BooleanFilterType;

final class UndeliveredFilter implements FilterInterface
{
    use FilterTrait;

    public static function new(string $propertyName, $label = null): self
    {
        return (new self())->setFilterFqcn(self::class)->setProperty($propertyName)->setLabel($label)
            ->setFormType(BooleanFilterType::class)->setFormTypeOption('mapped', false);
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $a = $filterDataDto->getEntityAlias();
        $queryBuilder->leftJoin($a . '.partner', 'delivery_partner');
        $email = sprintf("(delivery_partner.notifyEmails IS NOT NULL AND TRIM(delivery_partner.notifyEmails) <> '' AND %s.deliveredAt IS NULL)", $a);
        $webhook = sprintf("(delivery_partner.webhookUrl IS NOT NULL AND TRIM(delivery_partner.webhookUrl) <> '' AND %s.webhookDeliveredAt IS NULL)", $a);
        $problem = sprintf('(%s.nextAttemptAt IS NOT NULL OR (%s.attempts >= :delivery_max AND (%s OR %s)))', $a, $a, $email, $webhook);
        $queryBuilder->andWhere($filterDataDto->getValue() ? $problem : 'NOT ' . $problem)
            ->setParameter('delivery_max', PartnerNotifier::MAX_ATTEMPTS);
    }
}
