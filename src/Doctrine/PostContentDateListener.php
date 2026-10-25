<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\ServicePost;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Дата змістовного оновлення статті (ServicePost.contentUpdatedAt, 25.10.2026): на оновленні, коли по суті змінився заголовок,
 * лід чи текст будь-якою мовою. «По суті» — після прибирання тегів, сутностей, розділових знаків, пробілів і регістру текст
 * інший: типографіка (тире, лапки, нерозривні пробіли), розмітка, повторна публікація з тим самим текстом дату не зсувають.
 * Створення статті (імпорт) дату не ставить — dateModified лишається датою публікації.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
final class PostContentDateListener
{
    private const FIELDS = ['titleUk', 'titleRu', 'titleEn', 'leadUk', 'leadRu', 'leadEn', 'descUk', 'descRu', 'descEn'];

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $post = $args->getObject();
        if (!$post instanceof ServicePost) {
            return;
        }
        foreach (self::FIELDS as $field) {
            if ($args->hasChangedField($field)
                && self::normalize((string) $args->getOldValue($field)) !== self::normalize((string) $args->getNewValue($field))) {
                $post->setContentUpdatedAt(new \DateTimeImmutable());
                $em = $args->getObjectManager();
                $em->getUnitOfWork()->recomputeSingleEntityChangeSet($em->getClassMetadata(ServicePost::class), $post);

                return;
            }
        }
    }

    /**
     * Лише літери й цифри в нижньому регістрі: відкидає розмітку й типографіку. NFC — щоб «й» у розкладеній формі (NFD) не
     * читалось як зміна; невалідний UTF-8 (preg → null) лишає текст як є, а не порожній рядок (інакше два різні невалідні
     * тексти збіглися б).
     */
    public static function normalize(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;

        return mb_strtolower(preg_replace('~[^\p{L}\p{N}]+~u', '', $text) ?? $text);
    }
}
