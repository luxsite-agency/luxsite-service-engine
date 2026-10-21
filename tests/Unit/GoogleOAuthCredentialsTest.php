<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\GoogleKeySource;
use App\Security\GoogleOAuthCredentials;
use App\Settings\SecretBox;
use App\Settings\SiteSettingKeys as K;
use App\Tests\Support\SiteSettingsStubs;
use PHPUnit\Framework\TestCase;

/** Ключі Google: адмінка (перемикач + Client ID + секрет) > .env.local; неповні ключі з увімкненим перемикачем — вимкнено. */
final class GoogleOAuthCredentialsTest extends TestCase
{
    use SiteSettingsStubs;

    private const string ID = '123456789012-abcdefgh12345678.apps.googleusercontent.com';

    public function testEnvFallbackWhileSwitchIsOff(): void
    {
        $site = self::site(1);
        $creds = new GoogleOAuthCredentials($this->settingsWith([self::row($site, K::GOOGLE_CLIENT_ID, self::ID)]), 'env-id.apps.googleusercontent.com', 'env-not-a-secret');

        $r = $creds->resolve($site);
        self::assertSame(GoogleKeySource::Env, $r['source']);
        self::assertSame('env-id.apps.googleusercontent.com', $r['id']);
        self::assertSame(GoogleKeySource::Env, $creds->resolve(null)['source'], 'хост адмінки (без сайту) — лише env');

        $off = (new GoogleOAuthCredentials($this->settingsWith([])))->resolve($site);
        self::assertSame([GoogleKeySource::None, 'disabled'], [$off['source'], $off['problem']]);
    }

    public function testSettingsWinOverEnv(): void
    {
        $site = self::site(1);
        $box = new SecretBox(SecretBox::generateKey());
        $rows = [
            self::row($site, K::GOOGLE_SIGN_IN_ENABLED, '1'),
            self::row($site, K::GOOGLE_CLIENT_ID, self::ID),
            self::row($site, K::GOOGLE_CLIENT_SECRET, $box->encrypt('test-only-secret-wxyz')),
        ];
        $r = (new GoogleOAuthCredentials($this->settingsWith($rows, $box), 'env-id.apps.googleusercontent.com', 'env-not-a-secret'))->resolve($site);

        self::assertSame(GoogleKeySource::Settings, $r['source']);
        self::assertSame(self::ID, $r['id']);
        self::assertSame('test-only-secret-wxyz', $r['secret']);
        self::assertNull($r['problem']);
    }

    public function testEnabledButIncompleteDoesNotFallBackToEnv(): void
    {
        $site = self::site(1);
        $box = new SecretBox(SecretBox::generateKey());
        $env = ['env-id.apps.googleusercontent.com', 'env-not-a-secret'];
        $case = fn (array $rows, ?SecretBox $b = null): ?string => (new GoogleOAuthCredentials($this->settingsWith($rows, $b ?? $box), ...$env))->resolve($site)['problem'];
        $on = self::row($site, K::GOOGLE_SIGN_IN_ENABLED, '1');
        $id = self::row($site, K::GOOGLE_CLIENT_ID, self::ID);

        self::assertSame('client_id_missing', $case([$on]));
        self::assertSame('client_id_invalid', $case([$on, self::row($site, K::GOOGLE_CLIENT_ID, 'GOCSPX-wrong-field')]));
        self::assertSame('secret_missing', $case([$on, $id]));
        $envelope = $box->encrypt('test-only-secret-wxyz');
        self::assertSame('secret_no_key', $case([$on, $id, self::row($site, K::GOOGLE_CLIENT_SECRET, $envelope)], new SecretBox('')));
        self::assertSame('secret_unreadable', $case([$on, $id, self::row($site, K::GOOGLE_CLIENT_SECRET, $envelope)], new SecretBox(SecretBox::generateKey())));
    }

    public function testOtherSiteSettingsDoNotLeak(): void
    {
        $site = self::site(1);
        $other = self::site(2);
        $box = new SecretBox(SecretBox::generateKey());
        $rows = [
            self::row($other, K::GOOGLE_SIGN_IN_ENABLED, '1'),
            self::row($other, K::GOOGLE_CLIENT_ID, self::ID),
            self::row($other, K::GOOGLE_CLIENT_SECRET, $box->encrypt('test-only-secret-wxyz')),
        ];
        $creds = new GoogleOAuthCredentials($this->settingsWith($rows, $box));

        self::assertSame(GoogleKeySource::Settings, $creds->resolve($other)['source']);
        self::assertSame(GoogleKeySource::None, $creds->resolve($site)['source']);
    }
}
