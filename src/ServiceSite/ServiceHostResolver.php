<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceSiteRepository;

/**
 * Резолв сервісного сайту й міста за HTTP Host (дзеркало CityResolver для профілю
 * сервісних сайтів):
 *  - <site>.localhost            → апекс-місто сайту (dev, напр. example.localhost)
 *  - <city>.<site>.localhost     → місто по slug (dev, напр. north.example.localhost)
 *  - будь-який інший host        → пошук по ServiceCity.host (прод: апекс, піддомен
 *                                  чи окремий домен міста)
 * Хости міських сайтів (city-sites.localhost, прод-домени міст) сюди теж потрапляють,
 * але коштують один індексований SELECT і повертають null.
 */
final class ServiceHostResolver
{
    private const DEV_SUFFIX = '.localhost';

    public function __construct(
        private readonly ServiceSiteRepository $sites,
        private readonly ServiceCityRepository $cities,
    ) {
    }

    /** @return array{site: ServiceSite, city: ServiceCity}|null */
    public function resolveByHost(string $host): ?array
    {
        $host = strtolower(explode(':', $host)[0]);

        if (str_ends_with($host, self::DEV_SUFFIX)) {
            $parts = explode('.', substr($host, 0, -strlen(self::DEV_SUFFIX)));

            $site = $this->sites->findActiveBySlug(end($parts));
            if ($site === null) {
                return null;
            }

            $city = match (\count($parts)) {
                1 => $this->cities->findActiveApex($site),
                2 => $this->cities->findActiveBySiteAndSlug($site, $parts[0]),
                default => null,
            };

            return $city === null ? null : ['site' => $site, 'city' => $city];
        }

        $city = $this->cities->findActiveByHost($host);
        if ($city === null || !$city->getSite()->isActive()) {
            return null;
        }

        return ['site' => $city->getSite(), 'city' => $city];
    }
}
