<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ServiceCity;
use App\Repository\ServiceReviewRepository;
use App\ServiceSite\ReviewsRating;
use PHPUnit\Framework\TestCase;

final class ReviewsRatingTest extends TestCase
{
    public function testCityTokenWinsOverSharedToken(): void
    {
        $rating = $this->rating(['token' => 'shared', 'cities' => ['city-a' => ' token-a ']]);

        self::assertSame('token-a', $rating->gbpToken($this->city('city-a')));
        self::assertSame('shared', $rating->gbpToken($this->city('city-b')), 'місто без власного токена — спільний');
        self::assertSame('shared', $rating->gbpToken(), 'без міста — спільний');
    }

    public function testOnlyCityTokenLeavesOtherCitiesWithoutWidget(): void
    {
        // пак задає параметр цілком: {cities: {…}} без token/base
        $rating = $this->rating(['cities' => ['city-a' => 'token-a', 'city-c' => '']]);

        self::assertSame('token-a', $rating->gbpToken($this->city('city-a')));
        self::assertSame('', $rating->gbpToken($this->city('city-b')));
        self::assertSame('', $rating->gbpToken($this->city('city-c')), 'порожній токен міста = віджета нема');
    }

    public function testCityWithoutTokenKeepsOwnRating(): void
    {
        $own = ['avg' => 4.8, 'count' => 12];
        $reviews = $this->createMock(ServiceReviewRepository::class);
        $reviews->method('aggregateByCity')->willReturn($own);
        $rating = new ReviewsRating($reviews, true, ['cities' => ['city-a' => 'token-a']]);

        self::assertSame($own + ['source' => 'own', 'includes_site' => true], $rating->aggregate($this->city('city-b')));
        // місто з токеном: оцінка з gbp-manager; клієнта нема (і на сторінці з віджетом) — рушій мовчить, а не підставляє свою
        self::assertNull($rating->aggregate($this->city('city-a')));
        self::assertNull($rating->aggregate($this->city('city-a'), true));
    }

    /** @param array<string, mixed> $gbp */
    private function rating(array $gbp): ReviewsRating
    {
        return new ReviewsRating($this->createMock(ServiceReviewRepository::class), true, $gbp);
    }

    private function city(string $slug): ServiceCity
    {
        return (new ServiceCity())->setSlug($slug);
    }
}
