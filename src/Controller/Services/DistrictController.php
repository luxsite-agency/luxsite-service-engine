<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Entity\ServiceDistrict;
use App\Repository\ServiceCalcFactorRepository;
use App\Repository\ServiceCalcTypeRepository;
use App\Repository\ServiceDistrictRepository;
use App\Repository\ServiceItemRepository;
use App\ServiceSite\CalculatorAvailability;
use App\ServiceSite\ServiceCityAware;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Райони обслуговування міста: /districts/ і /districts/<slug>/.
 */
final class DistrictController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly ServiceDistrictRepository $districts,
        private readonly ServiceItemRepository $items,
        private readonly ServiceCalcTypeRepository $calcTypes,
        private readonly ServiceCalcFactorRepository $calcFactors,
        private readonly CalculatorAvailability $calculator,
    ) {
    }

    #[Route(
        path: ['uk' => '/districts/', 'ru' => '/ru/districts/'],
        name: 'sv_districts',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function index(Request $request): Response
    {
        $city = $this->serviceCity($request);
        $primary = $this->districts->findPrimaryByCity($city);
        $grouped = $this->districts->findActiveByCityGrouped($city);
        /** @var list<string> $suburbNames пак: app.district_suburb_groups (group_uk) */
        $suburbNames = (array) $this->getParameter('app.district_suburb_groups');

        if ($suburbNames !== []) {
            // Схема «адмінрайони + передмістя» (сайти з Joomla-імпортом, 10.2026): кожна група міста — акордеон з чипами місцевостей,
            // назва групи веде на її primary-сторінку; групи зі списку паку — окремий розділ «Передмістя».
            $cityGroups = [];
            $suburbGroups = [];
            foreach ($grouped as $group) {
                $name = $group['districts'][0]->getGroupUk();
                $primaryOf = null;
                foreach ($group['districts'] as $d) {
                    if ($d->isPrimary()) {
                        $primaryOf = $d;
                        break;
                    }
                }
                $group['primary'] = $primaryOf;
                if ($name !== null && \in_array($name, $suburbNames, true)) {
                    $suburbGroups[] = $group;
                } else {
                    $cityGroups[] = $group;
                }
            }

            return $this->render('services/district/index.html.twig', [
                'site' => $this->serviceSite($request),
                'city' => $city,
                'primary' => [],
                'cityGroups' => $cityGroups,
                'districtGroups' => $suburbGroups,
                'altUrls' => $this->altUrls('sv_districts'),
            ]);
        }

        // Стара схема (перші сайти): картки primary-районів; групи без своєї сторінки-району (передмістя) -
        // списком тут, бо інакше на них не було б жодного посилання
        $primaryGroups = array_map(static fn ($d) => $d->getGroupUk(), $primary);
        $orphanGroups = array_values(array_filter(
            $grouped,
            static fn (array $group) => !\in_array($group['districts'][0]->getGroupUk(), $primaryGroups, true),
        ));

        return $this->render('services/district/index.html.twig', [
            'site' => $this->serviceSite($request),
            'city' => $city,
            'primary' => $primary,
            'cityGroups' => [],
            'districtGroups' => $orphanGroups,
            'altUrls' => $this->altUrls('sv_districts'),
        ]);
    }

    #[Route(
        path: ['uk' => '/districts/{slug}/', 'ru' => '/ru/districts/{slug}/'],
        name: 'sv_district',
        priority: 10,
        condition: "request.attributes.has('service_city')",
    )]
    public function show(Request $request, string $slug): Response
    {
        $city = $this->serviceCity($request);
        $district = $this->districts->findOneActiveBySlug($city, $slug, $request->getLocale())
            ?? throw $this->createNotFoundException('Район не знайдено.');

        return $this->render('services/district/show.html.twig', [
            'site' => $this->serviceSite($request),
            'city' => $city,
            'district' => $district,
            'items' => $this->items->findActiveBySite($this->serviceSite($request), $city),
            // міні-калькулятор лише в режимі «за площею»
            'calcTypes' => $this->calculator->isArea($this->serviceSite($request), $city) ? $this->calcTypes->findActiveForCity($this->serviceSite($request), $city) : [],
            'calcFactors' => $this->calcFactors->findActiveBySiteGrouped($this->serviceSite($request)),
            // всередині району - його місцевості, всередині місцевості - сусіди
            'siblings' => $this->districts->findInGroup($district, $district->isPrimary() ? 40 : 12),
            'parent' => $district->isPrimary() ? null : $this->parentDistrict($district),
            // район без власного FAQ показує міський (пак: app.district_faq_fallback)
            'faq' => $district->loc('faq', $request->getLocale())
                ?: ($this->getParameter('app.district_faq_fallback') ? ($city->loc('faq', $request->getLocale()) ?? []) : []),
            'altUrls' => $this->altUrls('sv_district', static fn (string $l): array => ['slug' => $district->slug($l)]),
        ]);
    }

    /** Сторінка адмінрайону, до якого належить місцевість. */
    private function parentDistrict(ServiceDistrict $district): ?ServiceDistrict
    {
        foreach ($this->districts->findPrimaryByCity($district->getCity()) as $primary) {
            if ($primary->getGroupUk() === $district->getGroupUk()) {
                return $primary;
            }
        }

        return null;
    }
}
