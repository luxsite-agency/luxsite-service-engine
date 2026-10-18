<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi\Auth;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Відмова автентифікації API: код для `error=` у WWW-Authenticate (RFC 6750) і текст без подробиць, які допомогли б підбирати.
 * throttled → 429 з Retry-After, решта → 401 invalid_token.
 */
final class ApiTokenException extends AuthenticationException
{
    private string $error = 'invalid_token';
    private ?int $retryAfter = null;

    public static function invalid(string $description = 'Недійсний токен'): self
    {
        return new self($description);
    }

    public static function throttled(int $retryAfter): self
    {
        $e = new self('Забагато невдалих спроб, спробуйте пізніше');
        $e->error = 'too_many_requests';
        $e->retryAfter = max(1, $retryAfter);

        return $e;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function getMessageKey(): string
    {
        return $this->getMessage();
    }
}
