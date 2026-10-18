<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi\Auth;

use App\Entity\AdminApiToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** Поточний API-токен запиту (null поза firewall admin_api) — для /me і журналу змін. */
final class ApiTokenContext
{
    public const string ATTRIBUTE = 'admin_api_token';

    public function __construct(private readonly TokenStorageInterface $tokens)
    {
    }

    public function current(): ?AdminApiToken
    {
        $token = $this->tokens->getToken();
        if ($token === null || !$token->hasAttribute(self::ATTRIBUTE)) {
            return null;
        }
        $api = $token->getAttribute(self::ATTRIBUTE);

        return $api instanceof AdminApiToken ? $api : null;
    }
}
