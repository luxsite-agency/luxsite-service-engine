<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Settings\SecretBox;
use App\Settings\SecretUnreadableException;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testRoundTripKeepsOnlyEnvelopeAndMask(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $plain = 'test-only-value-abcd';
        $envelope = $box->encrypt($plain);

        self::assertTrue(SecretBox::isEnvelope($envelope));
        self::assertStringStartsWith('enc:v1:abcd:', $envelope);
        self::assertStringNotContainsString($plain, $envelope, 'відкритого тексту в конверті нема');
        self::assertSame('…abcd', SecretBox::mask($envelope));
        self::assertSame($plain, $box->decrypt($envelope));
        self::assertNotSame($envelope, $box->encrypt($plain), 'новий nonce на кожне шифрування');
    }

    public function testShortSecretHasNoMaskTail(): void
    {
        $box = new SecretBox(SecretBox::generateKey());

        self::assertSame('…', SecretBox::mask($box->encrypt('short')));
        self::assertNull(SecretBox::mask(null));
        self::assertNull(SecretBox::mask('plain text'));
    }

    public function testOtherKeyIsUnreadableNotFatal(): void
    {
        $envelope = (new SecretBox(SecretBox::generateKey()))->encrypt('test-only-value-1234');

        $this->expectException(SecretUnreadableException::class);
        (new SecretBox(SecretBox::generateKey()))->decrypt($envelope);
    }

    public function testKeyProblems(): void
    {
        self::assertSame('empty', (new SecretBox(''))->keyProblem());
        self::assertFalse((new SecretBox(''))->isAvailable());
        self::assertSame('invalid', (new SecretBox('not-base64-32-bytes'))->keyProblem());
        self::assertSame('invalid', (new SecretBox(base64_encode('too-short')))->keyProblem());
        self::assertNull((new SecretBox(SecretBox::generateKey()))->keyProblem());
        // ключ з переносом рядка в .env.local — trim, ключ придатний
        self::assertTrue((new SecretBox(SecretBox::generateKey() . "\n"))->isAvailable());
    }

    public function testEncryptWithoutKeyRefuses(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SecretBox(''))->encrypt('test-only-value');
    }
}
