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

    /** Мови розгортання й бренду (SiteLocales) — у локатор AbstractController */
    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [SiteLocales::class => SiteLocales::class];
    }

    /**
     * Абсолютні URL сторінки мовами бренду (canonical + hreflang + перемикач мов): мова → URL у порядку мов
     * розгортання (для типових uk/ru — {uk, ru}). Слаги різняться між мовами — тоді параметри передаються
     * функцією від мови: fn (string $l) => ['slug' => $post->slug($l)].
     *
     * @param array<string, string>|\Closure(string): array<string, string> $params
     *
     * @return array<string, string>
     */
    private function altUrls(string $route, array|\Closure $params = []): array
    {
        $urls = [];
        foreach ($this->container->get(SiteLocales::class)->enabled() as $locale) {
            $p = $params instanceof \Closure ? $params($locale) : $params;
            $urls[$locale] = $this->generateUrl($route, $p + ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $urls;
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
     * @param array<string, string> $altUrls
     *
     * @return array<string, string>|null
     */
    private function apexUrls(array $altUrls, ServiceCity $city, ?ServiceCity $apex): ?array
    {
        $host = $apex?->getHost();
        if ($host === null || $host === '' || $apex->getId() === $city->getId()) {
            return null;
        }

        return array_map(static fn (string $url): string => (string) preg_replace(
            '~^(https?://)[^/]+~', '$1' . $host, $url,
        ), $altUrls);
    }
}
