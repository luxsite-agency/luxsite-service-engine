<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\EventSubscriber;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * Коли Vich прибирає оригінал (заміна файлу в адмінці чи через admin API, DELETE …/image, видалення запису), прибрати
 * і його варіанти в кеші LiipImagine (public/media/cache/<фільтр>/media/<тека>/<ім'я>) — інакше shared/media/cache
 * на проді росте з кожною заміною фото. pre_remove: ім'я файлу ще в сутності.
 */
final class VichLiipCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CacheManager $cache, private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [Events::PRE_REMOVE => 'onPreRemove'];
    }

    public function onPreRemove(Event $event): void
    {
        $mapping = $event->getMapping();
        $name = $mapping->getFileName($event->getObject());
        if ($name === null || $name === '') {
            return;
        }
        $path = rtrim($mapping->getUriPrefix(), '/') . '/' . $name;
        try {
            $this->cache->remove($path);
        } catch (\Throwable $e) {
            // кеш — не дані: не зривати видалення оригіналу
            $this->logger->warning('liip_cache.remove_failed', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }
}
