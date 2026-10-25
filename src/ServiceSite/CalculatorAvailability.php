<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Repository\ServiceCalcTypeRepository;
use App\Repository\ServicePriceRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Чи є в місті калькулятор і який (рішення власника 24.10.2026 — стандарт рушія, не опція):
 *  - area — «за площею» (клінінг): у сайту є активні типи прибирання (service_calc_type);
 *  - pricelist — «за прайсом»: типів нема, але в прайсі міста є активні позиції з in_calculator=1
 *    (послуга надається в місті й ціни на неї не приховані);
 *  - null — калькулятора нема: сторінка 404, посилання на неї не рендеряться.
 * app.calculator.mode паку: auto (як вище) | area | pricelist (примусово один режим) | off.
 * Усе, що показує чи ховає калькулятор (кнопки, меню, sitemap, контролер), питає цей сервіс, а не репозиторії напряму.
 */
final class CalculatorAvailability
{
    public const MODE_AREA = 'area';
    public const MODE_PRICELIST = 'pricelist';
    public const MODES = ['auto', self::MODE_AREA, self::MODE_PRICELIST, 'off'];

    /** @var array<int, string|null> місто → режим (на запит) */
    private array $cache = [];

    public function __construct(
        private readonly ServiceCalcTypeRepository $calcTypes,
        private readonly ServicePriceRepository $prices,
        #[Autowire('%app.calculator.mode%')]
        private readonly string $mode = 'auto',
    ) {
        if (!\in_array($this->mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf('app.calculator.mode: очікується auto, area, pricelist або off, отримано «%s».', $this->mode));
        }
    }

    /** Режим калькулятора в місті або null, якщо калькулятора нема. */
    public function modeFor(ServiceSite $site, ServiceCity $city): ?string
    {
        return $this->cache[(int) $city->getId()] ??= $this->resolve($site, $city);
    }

    public function isAvailable(ServiceSite $site, ServiceCity $city): bool
    {
        return $this->modeFor($site, $city) !== null;
    }

    public function isArea(ServiceSite $site, ServiceCity $city): bool
    {
        return $this->modeFor($site, $city) === self::MODE_AREA;
    }

    public function isPricelist(ServiceSite $site, ServiceCity $city): bool
    {
        return $this->modeFor($site, $city) === self::MODE_PRICELIST;
    }

    private function resolve(ServiceSite $site, ServiceCity $city): ?string
    {
        if ($this->mode === 'off') {
            return null;
        }
        if ($this->mode !== self::MODE_PRICELIST && $this->calcTypes->findActiveBySite($site) !== []) {
            return self::MODE_AREA;
        }
        if ($this->mode !== self::MODE_AREA && $this->prices->hasPricelistCalculator($city)) {
            return self::MODE_PRICELIST;
        }

        return null;
    }
}
