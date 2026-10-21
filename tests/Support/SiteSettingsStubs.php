<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Entity\ServiceSiteSetting;
use App\Settings\SecretBox;
use App\Settings\SiteSettings;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Psr\Log\NullLogger;

/** Налаштування сайту без БД для юніт-тестів: рядки в пам'яті, сайти й міста з id (через reflection). */
trait SiteSettingsStubs
{
    /** @param list<ServiceSiteSetting> $rows */
    private function settingsWith(array $rows, ?SecretBox $box = null): SiteSettings
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturnCallback(static fn (array $criteria): array => array_values(array_filter(
            $rows,
            static fn (ServiceSiteSetting $r): bool => $r->getSite() === ($criteria['site'] ?? null),
        )));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new SiteSettings($em, $box ?? new SecretBox(''), new NullLogger());
    }

    private static function site(int $id, string $name = 'Example', string $apex = 'example.test'): ServiceSite
    {
        $site = (new ServiceSite())->setSlug('site' . $id)->setName($name)->setApexDomain($apex);
        (new \ReflectionProperty(ServiceSite::class, 'id'))->setValue($site, $id);

        return $site;
    }

    private static function city(ServiceSite $site, int $id, string $host, ?string $tag = null): ServiceCity
    {
        $city = (new ServiceCity())->setSite($site)->setHost($host)->setSlug('c' . $id)->setNameNomUk('Місто ' . $id)->setGtmId($tag);
        (new \ReflectionProperty(ServiceCity::class, 'id'))->setValue($city, $id);

        return $city;
    }

    private static function row(ServiceSite $site, string $code, ?string $value): ServiceSiteSetting
    {
        return (new ServiceSiteSetting($site, $code))->setValue($value);
    }
}
