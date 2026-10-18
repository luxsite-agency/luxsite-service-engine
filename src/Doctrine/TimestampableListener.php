<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Проставляє created_at / updated_at сутностям TimestampableInterface.
 * Поведінка як у попередньої реалізації (Gedmo Timestampable): на persist задані вручну дати не чіпаємо.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class TimestampableListener
{
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TimestampableInterface) {
            return;
        }
        $now = new \DateTime();
        if ($entity->getCreatedAt() === null) {
            $entity->setCreatedAt($now);
        }
        if ($entity->getUpdatedAt() === null) {
            $entity->setUpdatedAt($now);
        }
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof TimestampableInterface) {
            return;
        }
        $entity->setUpdatedAt(new \DateTime());
        // preUpdate іде після обчислення changeset - без перерахунку нове значення не потрапить в UPDATE
        $em = $args->getObjectManager();
        $em->getUnitOfWork()->recomputeSingleEntityChangeSet($em->getClassMetadata($entity::class), $entity);
    }
}
