<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\AdminHostSubscriber;
use App\EventSubscriber\PartnerCabinetSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Кабінет — цілий сегмент /partner. Сторінки сайтів, що лише починаються з цих літер (/partners/, /partnery/,
 * /partnership/), кабінет і обмеження хоста адмінки не чіпають.
 */
final class PartnerCabinetPathTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function paths(): iterable
    {
        yield 'кабінет' => ['/partner', true];
        yield 'сторінка кабінету' => ['/partner/partner-lead', true];
        yield 'рахунок' => ['/partner/billing', true];
        yield 'сторінка partners' => ['/partners/', false];
        yield 'сторінка partnery' => ['/partnery/', false];
        yield 'partnership' => ['/partnership', false];
        yield 'інше' => ['/services/partner/', false];
    }

    #[DataProvider('paths')]
    public function testCabinetPathIsWholeSegment(string $path, bool $cabinet): void
    {
        self::assertSame($cabinet, PartnerCabinetSubscriber::isCabinetPath($path));
    }

    public function testSitePagesStartingWithPartnerAreNotBlockedOnSiteHosts(): void
    {
        $subscriber = new AdminHostSubscriber('admin.example.test');
        foreach (['/partners/', '/partnery/', '/partnership/'] as $path) {
            $subscriber->onKernelRequest(self::event('https://site.example.test' . $path));
        }
        $this->expectException(NotFoundHttpException::class);
        $subscriber->onKernelRequest(self::event('https://site.example.test/partner'));
    }

    private static function event(string $url): RequestEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
            {
                throw new \LogicException('not used');
            }
        };

        return new RequestEvent($kernel, Request::create($url), HttpKernelInterface::MAIN_REQUEST);
    }
}
