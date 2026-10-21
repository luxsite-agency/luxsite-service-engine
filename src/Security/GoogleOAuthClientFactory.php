<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Security;

use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\OAuth2ClientInterface;
use KnpU\OAuth2ClientBundle\Client\Provider\GoogleClient;
use League\OAuth2\Client\Provider\Google;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * OAuth-клієнт Google для сайту поточного хоста. Ключі з адмінки — свій клієнт league/oauth2-google з redirect URI на
 * хості запиту (https://<хост міста>/connect/google/check: у сайту кілька доменів, кожен — окремий redirect URI в Google
 * Cloud Console, панель «Вхід через Google» їх перелічує). Ключі з .env.local — клієнт knpu `google`, як до 21.10.2026.
 */
final class GoogleOAuthClientFactory
{
    public const string CHECK_ROUTE = 'connect_google_check';

    public function __construct(
        private readonly GoogleOAuthCredentials $credentials,
        private readonly ClientRegistry $registry,
        private readonly RequestStack $requests,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** null — вхід для цього сайту вимкнено. */
    public function forRequest(Request $request): ?OAuth2ClientInterface
    {
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        $resolved = $this->credentials->resolve($site instanceof ServiceSite ? $site : null);

        return match ($resolved['source']) {
            GoogleKeySource::None => null,
            GoogleKeySource::Env => $this->registry->getClient('google'),
            GoogleKeySource::Settings => new GoogleClient(new Google([
                'clientId' => $resolved['id'],
                'clientSecret' => $resolved['secret'],
                'redirectUri' => $this->urls->generate(self::CHECK_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]), $this->requests),
        };
    }
}
