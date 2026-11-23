<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ServicePage;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CatalogAdminLocalesTest extends SeoTestCase
{
    public function testModeratorDoesNotSeeEnglishFieldsInDefaultDeployment(): void
    {
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        foreach (['service-item', 'service-category', 'service-district', 'service-page', 'service-item-city',
            'service-category-city', 'service-price', 'service-team-member', 'service-review', 'service-work'] as $route) {
            $response = $this->http('GET', 'https://' . $this->adminHost . '/admin/' . $route . '/new');
            self::assertSame(200, $response->getStatusCode(), $route);
            self::assertDoesNotMatchRegularExpression('~name="Service\w+\[[a-zA-Z0-9]+En\]~', (string) $response->getContent(), $route);
        }
        $page = (new ServicePage())->setSite($this->site())->setSlugEn('english-only')->setTitleEn('English only');
        $paths = [];
        foreach (static::getContainer()->get(ValidatorInterface::class)->validate($page) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }
        self::assertContains('slugUk', $paths, 'default deployment still requires Ukrainian slug');
        self::assertContains('titleUk', $paths, 'default deployment still requires Ukrainian title');
    }
}
