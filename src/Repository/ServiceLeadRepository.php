<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ServiceCity;
use App\Entity\ServiceLead;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceLead>
 */
class ServiceLeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceLead::class);
    }

    /**
     * Остання заявка з тим самим номером у місті за вікно часу. Номер порівнюємо
     * за останніми 9 цифрами: «0937593844» і «380937593844» - одна людина.
     * Захищає від подвійного натискання й повторної відправки після «назад»
     * (на першому сайті було по дві однакові заявки з інтервалом 1-3 с).
     */
    /** Та сама адреса з форми зв'язку в цьому місті за останні $seconds секунд (подвійне натискання) */
    public function findRecentDuplicateEmail(ServiceCity $city, string $email, int $seconds): ?ServiceLead
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.city = :city')->setParameter('city', $city)
            ->andWhere('l.email = :email')->setParameter('email', $email)
            ->andWhere('l.createdAt >= :since')->setParameter('since', new \DateTimeImmutable(sprintf('-%d seconds', $seconds)))
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function findRecentDuplicate(ServiceCity $city, string $phone, int $seconds): ?ServiceLead
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $tail = substr($digits, -9);
        if (\strlen($tail) < 9) {
            return null;
        }

        $candidates = $this->createQueryBuilder('l')
            ->andWhere('l.city = :city')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('city', $city)
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d seconds', $seconds)))
            ->orderBy('l.id', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        foreach ($candidates as $lead) {
            $other = preg_replace('/\D/', '', $lead->getPhone()) ?? '';
            if (substr($other, -9) === $tail) {
                return $lead;
            }
        }

        return null;
    }
}
