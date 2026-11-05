<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

final class PartnerCabinetDisabledTest extends SeoTestCase
{
    public function testDisabledCabinetIs404BeforeAuthentication(): void
    {
        foreach (['/partner', '/partner/billing', '/partner/partner-lead', '/partner/partner-lead/1/edit'] as $path) {
            self::assertSame(404, $this->http('GET', 'https://' . $this->adminHost . $path)->getStatusCode(), $path);
        }
        $this->login($this->adminHost, $this->moderatorEmail, $this->password);
        self::assertSame(404, $this->http('GET', 'https://' . $this->adminHost . '/partner')->getStatusCode());
    }
}
