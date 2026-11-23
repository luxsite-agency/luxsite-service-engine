<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\ServicePage;
use App\Entity\User;
use App\Tests\Support\EnglishCatalogKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** slug_en задовгий для колонки (160, у районів 64) через /api/admin/v1 — 422 з помилкою поля, не 500 (рев'ю gls-rev W2) */
final class SlugEnLengthApiTest extends SeoTestCase
{
    protected static function getKernelClass(): string
    {
        return EnglishCatalogKernel::class;
    }

    public function testTooLongEnglishSlugIs422(): void
    {
        $page = (new ServicePage())->setSite($this->site())->setSlugUk('page-' . $this->s)->setTitleUk('Сторінка');
        $this->em()->persist($page);
        $this->em()->flush();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $this->moderatorEmail]);
        $token = static::getContainer()->get(AdminApiTokens::class)->issue($user, 'ci slug_en', [ApiScope::ContentRead, ApiScope::ContentWrite], 1, $user)->plain;
        // своя літера на запис: однакові слаги кореня (схема slug) — інша помилка (конфлікт адрес), не та, що перевіряємо
        foreach (['items/' . $this->itemId => [161, 'a'], 'categories/' . $this->categoryId => [161, 'b'], 'pages/' . $page->getId() => [161, 'c'], 'districts/' . $this->districtId => [65, 'd']] as $path => [$len, $ch]) {
            $r = $this->api($token, $path, ['slug_en' => str_repeat($ch, $len)]);
            self::assertSame(422, $r->getStatusCode(), $path . ': ' . mb_substr((string) $r->getContent(), 0, 300));
            self::assertStringContainsString('slugEn', json_decode((string) $r->getContent(), true)['error'] ?? '', $path);
            self::assertSame(200, $this->api($token, $path, ['slug_en' => str_repeat($ch, $len - 1)])->getStatusCode(), $path . ': межа колонки проходить');
        }
    }

    /** @param array<string, mixed> $body */
    private function api(string $token, string $path, array $body): Response
    {
        $kernel = static::$kernel ?? throw new \LogicException('bootKernel() спершу');
        $request = Request::create('https://' . $this->adminHost . '/api/admin/v1/' . $this->slug . '/' . $path, 'PATCH', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode($body));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }
}
