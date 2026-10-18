<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Security\GoogleOAuthConfig;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GoogleController extends AbstractController
{
    public function __construct(
        private readonly GoogleOAuthConfig $googleOAuth,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Старт: запам'ятовуємо, куди повернутись, і йдемо до Google */
    #[Route('/connect/google', name: 'connect_google_start')]
    public function connect(Request $request, ClientRegistry $clients): Response
    {
        $return = (string) $request->query->get('return', '/');
        if ($return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
            $return = '/';
        }

        // без клієнта Google віддасть «Missing required parameter: client_id». Кнопки на сайті тоді нема
        // (google_oauth.configured), сюди веде хіба стара закладка чи entry point: тихо назад, технічне — лише в лог
        if (!$this->googleOAuth->isConfigured()) {
            $this->logger->warning($this->googleOAuth->warning(), ['host' => $request->getHost()]);

            return $this->redirect($return);
        }

        $request->getSession()->set('oauth_return', $return);

        return $clients->getClient('google')->redirect(['openid', 'email', 'profile'], []);
    }

    /** Callback: перехоплюється GoogleAuthenticator, тіло не виконується */
    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function check(): Response
    {
        throw new \LogicException('Перехоплюється GoogleAuthenticator.');
    }
}
