<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Доступ контролерів сервісного профілю до тенанта з request attributes
 * (їх кладе ServiceHostSubscriber; guard — на випадок роута без condition).
 */
trait ServiceCityAware
{
    private function serviceCity(Request $request): ServiceCity
    {
        $city = $request->attributes->get(ServiceHostSubscriber::ATTR_CITY);
        if (!$city instanceof ServiceCity) {
            throw new NotFoundHttpException('Сервісне місто не визначено за хостом.');
        }

        return $city;
    }

    private function serviceSite(Request $request): ServiceSite
    {
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        if (!$site instanceof ServiceSite) {
            throw new NotFoundHttpException('Сервісний сайт не визначено за хостом.');
        }

        return $site;
    }

    /**
     * Абсолютні URL сторінки в обох локалях (canonical + hreflang + перемикач мов).
     * Слаги різняться між мовами — ru-параметри передаються окремо, коли треба.
     *
     * @param array<string, string> $ukParams
     * @param array<string, string>|null $ruParams null = ті самі, що uk
     *
     * @return array{uk: string, ru: string}
     */
    private function altUrls(string $route, array $ukParams = [], ?array $ruParams = null): array
    {
        return [
            'uk' => $this->generateUrl($route, $ukParams + ['_locale' => 'uk'], UrlGeneratorInterface::ABSOLUTE_URL),
            'ru' => $this->generateUrl($route, ($ruParams ?? $ukParams) + ['_locale' => 'ru'], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    /**
     * Ті самі адреси, але на головному домені. Потрібно там, де сторінка
     * однакова в усіх містах - блог: чотири копії конкурують між собою, тож
     * canonical з міських хостів веде на версію апекса.
     *
     * Повертає null, якщо ми вже на апексі або його немає - тоді сторінка
     * канонічна сама на себе, як і решта.
     *
     * ⚠️ Тільки для справді однакових сторінок. Для послуг так робити не
     * можна: «прибирання квартир Харків» - окремий запит, і canonical на
     * Київ означав би відмову від харківської видачі.
     *
     * @param array{uk: string, ru: string} $altUrls
     *
     * @return array{uk: string, ru: string}|null
     */
    private function apexUrls(array $altUrls, ServiceCity $city, ?ServiceCity $apex): ?array
    {
        $host = $apex?->getHost();
        if ($host === null || $host === '' || $apex->getId() === $city->getId()) {
            return null;
        }

        $swap = static fn (string $url): string => (string) preg_replace(
            '~^(https?://)[^/]+~', '$1' . $host, $url,
        );

        return ['uk' => $swap($altUrls['uk']), 'ru' => $swap($altUrls['ru'])];
    }
}
