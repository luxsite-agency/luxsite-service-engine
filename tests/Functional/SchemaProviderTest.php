<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Functional;

final class SchemaProviderTest extends SeoTestCase
{
    public function testProviderPhoneIsOptionalAndFixedPagesDefaultToEnabled(): void
    {
        foreach ([null, '+380441234567'] as $phone) {
            $this->city($this->apexCityId)->setPhone($phone);
            $this->em()->flush();
            $response = $this->http('GET', 'https://' . $this->apex . '/services/item-' . $this->s . '/');
            self::assertSame(200, $response->getStatusCode());
            preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', (string) $response->getContent(), $matches);
            $providers = [];
            foreach ($matches[1] as $json) {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (($data['@type'] ?? null) === 'Service') {
                    $providers[] = $data['provider'];
                }
            }
            self::assertCount(1, $providers);
            if ($phone === null) {
                self::assertArrayNotHasKey('telephone', $providers[0]);
            } else {
                self::assertSame($phone, $providers[0]['telephone']);
            }
        }
        foreach (['faq', 'contacts', 'reviews', 'privacy-policy', 'terms-of-service'] as $path) {
            self::assertSame(200, $this->http('GET', 'https://' . $this->apex . '/' . $path . '/')->getStatusCode());
        }
    }
}
