<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\ServiceCity;
use App\ServiceSite\SiteLocales;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Мови без фолбеку на uk для міста (рев'ю gls-rev W1, 23.11.2026): на [uk, ru, en] en-сторінка не показує українську назву
 * чи адресу міста (ServiceCity::name()/address()). Залежить лише від app.locales, не від запиту — тож мітка на кожне
 * завантажене місто, включно з містами перемикача.
 */
#[AsEntityListener(event: Events::postLoad, entity: ServiceCity::class)]
final class CityLocalesListener
{
    /** @var list<string>|null */
    private ?array $locales = null;

    public function __construct(private readonly SiteLocales $siteLocales)
    {
    }

    public function postLoad(ServiceCity $city): void
    {
        $this->locales ??= array_values(array_filter($this->siteLocales->deployment(), $this->siteLocales->needsOwnContent(...)));
        if ($this->locales !== []) {
            $city->markOwnContentLocales($this->locales);
        }
    }
}
