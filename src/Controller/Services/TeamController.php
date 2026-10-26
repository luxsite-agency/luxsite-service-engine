<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Entity\ServiceCity;
use App\Entity\ServiceItem;
use App\Entity\ServiceTeamMember;
use App\Repository\ServiceTeamMemberRepository;
use App\ServiceSite\CrossCityDistricts;
use App\ServiceSite\ServiceCityAware;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сторінки команди (майстри, лікарі; 10.2026). Маршрути — config/routes/team/team.yaml, лише коли пак задав
 * app.team_prefix (src/Kernel.php::configureRoutes): без префікса сайт сторінок команди не має.
 *
 *  - /<префікс>/ — команда міста хоста (основне місто людини або одне з інших), canonical на себе: склад різний по містах;
 *  - /<префікс>/<слаг>/ — людина. На хості основного міста — канонічна; на хості іншого міста, де людина теж працює, —
 *    та сама сторінка (послуги цього міста), але canonical і hreflang ведуть на основне; на хості міста, де людина не
 *    працює, — 301 на основне місто (або 404, якщо воно вимкнене).
 */
final class TeamController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly ServiceTeamMemberRepository $team,
        private readonly CrossCityDistricts $crossCity,
    ) {
    }

    public function index(Request $request): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);
        $team = $this->team->findActiveByCity($city);
        // місто без команди — сторінки нема (порожній список не потрібен ні людям, ні пошуку); пункту меню теж нема
        if ($team === []) {
            throw $this->createNotFoundException('У цьому місті команду ще не наповнено.');
        }

        return $this->render('services/team/index.html.twig', [
            'site' => $site,
            'city' => $city,
            'team' => $team,
            'altUrls' => $this->altUrls('sv_team'),
        ]);
    }

    public function show(Request $request, string $slug): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);
        $locale = $request->getLocale();
        $member = $this->team->findOneActiveBySiteAndSlug($site, $slug, $locale)
            ?? throw $this->createNotFoundException('Такої людини в команді нема.');
        $primary = $member->getCity();

        if (!$member->worksIn($city)) {
            // людина працює в іншому місті сайту → 301 на її сторінку там (query з utm/gclid — разом), інакше 404
            if ($primary === null || !$primary->isActive()) {
                throw $this->createNotFoundException('Людина працює в місті, якого на сайті вже нема.');
            }
            $query = $request->getQueryString();

            return $this->redirect($this->onHost($primary, $request, $this->generateUrl('sv_team_member', ['slug' => $slug, '_locale' => $locale]))
                . ($query !== null && $query !== '' ? '?' . $query : ''), 301);
        }

        $altUrls = $this->altUrls(
            'sv_team_member',
            ['slug' => (string) $member->slug('uk')],
            ['slug' => (string) $member->slug('ru')],
        );
        $canonicalUrls = null;
        if ($primary !== null && $primary->getId() !== $city->getId()) {
            $canonicalUrls = [
                'uk' => $this->swapHost($altUrls['uk'], $primary, $request),
                'ru' => $this->swapHost($altUrls['ru'], $primary, $request),
            ];
        }

        // філії, де людина приймає (активні міста: основне першим) — блок на сторінці й worksFor у JSON-LD
        $branches = [];
        foreach ($member->allCities() as $c) {
            if ($c->isActive() && $c->getSite()?->getId() === $site->getId()) {
                $branches[] = ['city' => $c, 'url' => $request->getScheme() . '://' . $this->crossCity->hostFor($c, $site, $request) . '/'];
            }
        }

        return $this->render('services/team/show.html.twig', [
            'site' => $site,
            'city' => $city,
            'member' => $member,
            'services' => $this->servicesIn($member, $city),
            'branches' => $branches,
            'altUrls' => $altUrls,
            'canonicalUrls' => $canonicalUrls,
        ]);
    }

    /**
     * Послуги людини, які є на сайті в цьому місті (активні, розділ активний, послуга тут надається): порядок каталогу.
     *
     * @return list<ServiceItem>
     */
    private function servicesIn(ServiceTeamMember $member, ServiceCity $city): array
    {
        $out = array_values(array_filter(
            $member->getItems()->toArray(),
            static fn (ServiceItem $i) => $i->isActive() && $i->getCategory()?->isActive() && $i->isAvailableIn($city)
                && $i->getSite()?->getId() === $city->getSite()?->getId(),
        ));
        usort($out, static fn (ServiceItem $a, ServiceItem $b) => [$a->getCategory()->getPosition(), $a->getPosition(), $a->getId()]
            <=> [$b->getCategory()->getPosition(), $b->getPosition(), $b->getId()]);

        return $out;
    }

    private function onHost(ServiceCity $target, Request $request, string $path): string
    {
        return $request->getScheme() . '://' . $this->crossCity->hostFor($target, $this->serviceSite($request), $request) . $path;
    }

    private function swapHost(string $url, ServiceCity $target, Request $request): string
    {
        return $this->onHost($target, $request, (string) parse_url($url, PHP_URL_PATH));
    }
}
