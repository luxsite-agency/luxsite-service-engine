<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use League\OAuth2\Client\Provider\GoogleUser;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Вхід через Google на брендових хостах (для відгуків/UGC) — патерн city-sites.
 * Юзер створюється з ROLE_USER; зв'язка по googleId, фолбек по email
 * (локальний адмін-акаунт з тим самим email отримує googleId — це той самий юзер).
 */
class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private readonly ClientRegistry $clients,
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

    public function authenticate(Request $request): Passport
    {
        $client = $this->clients->getClient('google');
        $accessToken = $this->fetchAccessToken($client);

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $client): User {
                /** @var GoogleUser $googleUser */
                $googleUser = $client->fetchUserFromToken($accessToken);

                $googleId = (string) $googleUser->getId();
                $email = (string) $googleUser->getEmail();
                if ($googleId === '' || $email === '') {
                    throw new AuthenticationException('Google не повернув id або email.');
                }

                // Google дозволяє акаунти з НЕпідтвердженим email (кастомні домени):
                // без цієї перевірки можна прив'язатися до чужого локального акаунта
                if (($googleUser->toArray()['email_verified'] ?? false) !== true) {
                    throw new AuthenticationException('Email у Google-акаунті не підтверджено.');
                }

                $user = $this->users->findOneBy(['googleId' => $googleId])
                    ?? $this->users->findOneBy(['email' => $email]);

                if ($user !== null && $user->getGoogleId() !== null && $user->getGoogleId() !== $googleId) {
                    // email вже прив'язаний до ІНШОГО Google-акаунта — не даємо
                    // перезаписати googleId (захист від account takeover)
                    throw new AuthenticationException('Акаунт із цим email вже прив\'язаний до іншого Google-акаунта.');
                }

                if ($user === null) {
                    $user = (new User())
                        ->setEmail($email)
                        ->setRoles(['ROLE_USER'])
                        ->setPassword(''); // вхід тільки через Google
                    $this->em->persist($user);
                }

                $user->setGoogleId($googleId);
                if ($user->getDisplayName() === null) {
                    $user->setDisplayName((string) $googleUser->getName());
                }
                $avatar = (string) $googleUser->getAvatar();
                $user->setAvatarUrl(str_starts_with($avatar, 'https://') ? $avatar : null);
                $this->em->flush();

                return $user;
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $return = (string) $request->getSession()->remove('oauth_return');
        if ($return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
            $return = '/';
        }

        return new RedirectResponse($return);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // без деталей назовні; причина — тільки в лог, без PII
        $this->logger->warning('Google-вхід відхилено: {reason}', [
            'reason' => $exception->getMessage(),
            'host' => $request->getHost(),
        ]);

        return new RedirectResponse('/');
    }
}
