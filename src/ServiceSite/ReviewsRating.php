<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use App\Repository\ServiceReviewRepository;
use Luxsite\GmbReviews\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Загальна оцінка, яку рушій показує (рядок біля блоку відгуків) і кладе в JSON-LD (aggregateRating) — одна на сторінці.
 *
 * Власник 18.10.2026: «залишаємо тільки нижнє» і «загальна цифра в кожному блоці відгуків».
 *  - Підключено віджет gbp-manager (app.gbp_reviews.token): числа — ті самі, що в шапці віджета (вибір «Яку оцінку
 *    показувати» в gbp-manager), через пакет luxsite/gmb-reviews-core (кеш, stale до 7 діб; сервер недоступний і кешу
 *    нема — null, блок без цифри). На сторінці, де стоїть сам віджет (/reviews/), рушій оцінку не друкує — її і розмітку
 *    дає віджет.
 *  - Віджета нема: власні відгуки рушія (service_review), як було; app.reviews.own_rating: false вимикає й це.
 *
 * @phpstan-type Aggregate array{avg: float, count: int, source: 'own'|'gbp', includes_site: bool}
 */
final class ReviewsRating
{
    /** @param array{token?: string, base?: string} $gbp */
    public function __construct(
        private readonly ServiceReviewRepository $reviews,
        #[Autowire('%app.reviews.own_rating%')]
        private readonly bool $ownRating = true,
        #[Autowire('%app.gbp_reviews%')]
        private readonly array $gbp = [],
        private readonly ?Client $client = null,
    ) {
    }

    public function gbpToken(): string
    {
        return trim((string) ($this->gbp['token'] ?? ''));
    }

    /**
     * @param bool $widgetOnPage на сторінці стоїть сам віджет (він показує оцінку й розмітку) — рушій мовчить
     *
     * @return Aggregate|null
     */
    public function aggregate(ServiceCity $city, bool $widgetOnPage = false): ?array
    {
        $token = $this->gbpToken();
        if ('' !== $token) {
            if ($widgetOnPage || null === $this->client) {
                return null;
            }
            try {
                $r = $this->client->rating($token);
            } catch (\Throwable) {
                $r = null; // збій gbp-manager не ламає сторінку
            }

            return null === $r ? null : ['avg' => $r['value'], 'count' => $r['count'], 'source' => 'gbp', 'includes_site' => $r['includes_site']];
        }
        if (!$this->ownRating) {
            return null;
        }
        $own = $this->reviews->aggregateByCity($city);

        return null === $own ? null : $own + ['source' => 'own', 'includes_site' => true];
    }
}
