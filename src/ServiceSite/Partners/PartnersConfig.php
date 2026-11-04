<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Partners;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Конфігурація паку перевіряється до першої маршрутизації. */
final class PartnersConfig
{
    public readonly bool $enabled;
    public readonly int $shareMax;
    public readonly bool $unroutedNotify;
    public readonly string $zipFromKey;
    public readonly string $zipToKey;
    public readonly \DateTimeZone $timezone;

    /** @param array<string, mixed> $config */
    public function __construct(
        #[Autowire(param: 'app.partners')] array $config = [],
        #[Autowire(param: 'app.timezone')] string $timezone = 'Europe/Kyiv',
    ) {
        $config += ['enabled' => false, 'share_max' => 3, 'unrouted_notify' => true, 'zip_from_key' => 'zip_from', 'zip_to_key' => 'zip_to'];
        if (!\is_bool($config['enabled']) || !\is_bool($config['unrouted_notify'])) {
            throw new \InvalidArgumentException('app.partners: enabled та unrouted_notify мають бути булевими.');
        }
        if (!\is_int($config['share_max']) || $config['share_max'] < 1 || $config['share_max'] > 10) {
            throw new \InvalidArgumentException('app.partners.share_max: ціле число від 1 до 10.');
        }
        foreach (['zip_from_key', 'zip_to_key'] as $key) {
            if (!\is_string($config[$key]) || trim($config[$key]) === '') {
                throw new \InvalidArgumentException('app.partners.' . $key . ': непорожній рядок.');
            }
        }
        try {
            $this->timezone = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('app.timezone: невідомий часовий пояс.', 0, $e);
        }
        $this->enabled = $config['enabled'];
        $this->shareMax = $config['share_max'];
        $this->unroutedNotify = $config['unrouted_notify'];
        $this->zipFromKey = $config['zip_from_key'];
        $this->zipToKey = $config['zip_to_key'];
    }

    public static function normalizeZip(?string $value): ?string
    {
        $zip = substr(preg_replace('/[^0-9]/', '', $value ?? '') ?? '', 0, 10);

        return $zip === '' ? null : $zip;
    }
}
