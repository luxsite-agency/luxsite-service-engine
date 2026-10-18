<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Незалогінені: на адмін-хості → форма /login, на брендових доменах → Google
 * (бо /login там 404 через AdminHostSubscriber). Патерн city-sites.
 */
final class AppAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(env: 'ADMIN_HOST')]
        private readonly string $adminHost,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $host = strtolower($request->getHost());

        if ($this->adminHost !== '' && $host === strtolower($this->adminHost)) {
            return new RedirectResponse($this->urls->generate('app_login'));
        }

        return new RedirectResponse($this->urls->generate('connect_google_start', [
            'return' => $request->getPathInfo(),
        ]));
    }
}
