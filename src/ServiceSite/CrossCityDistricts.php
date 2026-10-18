<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceDistrictRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Район із slug, якого нема в поточному місті, але є в іншому активному місті того ж сайту → адреса на його хості.
 * Старий Joomla-сайт показував райони одного міста на піддомені іншого й навпаки (120 адрес, 330 показів).
 * Використовують CrossCityDistrictSubscriber (404 → 301) і LegacyUrlSubscriber (адреса без слеша → одразу правильний
 * хост одним 301, а не слеш → свій хост → чужий). Вимикається app.clean_urls.cross_city: false.
 */
final class CrossCityDistricts
{
    private const DEV_SUFFIX = '.localhost';
    public const PATH_RE = '~^/(ru/)?districts/([^/]+)/?$~';

    /** @param array<string, mixed> $clean */
    public function __construct(
        private readonly ServiceCityRepository $cities,
        private readonly ServiceDistrictRepository $districts,
        #[Autowire('%app.clean_urls%')] private readonly array $clean = [],
    ) {
    }

    public function enabled(): bool
    {
        return ($this->clean['cross_city'] ?? true) !== false;
    }

    /**
     * Абсолютна адреса (без query) району на хості іншого міста або null: шлях не районний, район є в поточному місті,
     * або його нема ніде.
     */
    public function target(Request $request, ServiceSite $site, ServiceCity $city, string $path): ?string
    {
        if (!$this->enabled() || !preg_match(self::PATH_RE, $path, $m)) {
            return null;
        }
        $locale = $m[1] !== '' ? 'ru' : 'uk';
        $slug = $m[2];
        if ($this->districts->findOneActiveBySlug($city, $slug, $locale) !== null) {
            return null; // свій район — звичайна сторінка (або лише слеш)
        }
        foreach ($this->cities->findAllActiveBySite($site) as $other) {
            if ($other->getId() === $city->getId() || $this->districts->findOneActiveBySlug($other, $slug, $locale) === null) {
                continue;
            }

            return $request->getScheme() . '://' . $this->hostFor($other, $site, $request)
                . ($locale === 'ru' ? '/ru' : '') . '/districts/' . $slug . '/';
        }

        return null;
    }

    /** Хост іншого міста: прод — ServiceCity.host; dev-стенд <site>.localhost — <city>.<site>.localhost або апекс. */
    public function hostFor(ServiceCity $other, ServiceSite $site, Request $request): string
    {
        if (str_ends_with(strtolower($request->getHost()), self::DEV_SUFFIX)) {
            return ($other->isApex() ? '' : $other->getSlug() . '.') . $site->getSlug() . self::DEV_SUFFIX;
        }

        return $other->getHost();
    }
}
