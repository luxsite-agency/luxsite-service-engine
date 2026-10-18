<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi\Auth;

use App\AdminApi\AdminApiTokens;
use App\Entity\AdminApiToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Bearer-автентифікація firewall admin_api: `Authorization: Bearer svc_…` → хеш → AdminApiToken → власник. Без сесії.
 * Невдалі спроби лічаться з IP (limiter admin_api_auth_ip) і йдуть у лог без значення токена (лише префікс).
 * Відповіді за RFC 6750: 401 + WWW-Authenticate: Bearer realm, error, error_description; 429 + Retry-After.
 */
final class ApiTokenAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const string REALM = 'service-admin-api';

    public function __construct(
        private readonly AdminApiTokens $tokens,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.admin_api_auth_ip')] private readonly RateLimiterFactoryInterface $authLimiter,
    ) {
    }

    public function supports(Request $request): bool
    {
        return str_starts_with((string) $request->headers->get('Authorization', ''), 'Bearer ');
    }

    public function authenticate(Request $request): Passport
    {
        $ip = $request->getClientIp() ?? 'anon';
        $limiter = $this->authLimiter->create($ip);
        $limit = $limiter->consume(0);
        if (!$limit->isAccepted()) {
            throw ApiTokenException::throttled($limit->getRetryAfter()->getTimestamp() - time());
        }
        $plain = trim(substr((string) $request->headers->get('Authorization', ''), 7));
        $token = $this->tokens->findByPlain($plain);
        $prefix = AdminApiTokens::looksLikeToken($plain) ? substr($plain, 0, AdminApiToken::PREFIX_LENGTH) : null;
        if ($token === null) {
            throw $this->failure($limiter->consume(), $ip, 'unknown', $prefix, null);
        }
        if ($token->isRevoked()) {
            throw $this->failure($limiter->consume(), $ip, 'revoked', $prefix, $token, 'Токен відкликано');
        }
        if ($token->isExpired()) {
            throw $this->failure($limiter->consume(), $ip, 'expired', $prefix, $token, 'Строк дії токена минув');
        }
        $user = $token->getUser();

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user), [new ApiTokenBadge($token)]);
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $token = new PostAuthenticationToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());
        $badge = $passport->getBadge(ApiTokenBadge::class);
        if ($badge instanceof ApiTokenBadge) {
            $token->setAttribute(ApiTokenContext::ATTRIBUTE, $badge->token);
        }

        return $token;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $api = $token->hasAttribute(ApiTokenContext::ATTRIBUTE) ? $token->getAttribute(ApiTokenContext::ATTRIBUTE) : null;
        if ($api instanceof AdminApiToken) {
            $this->tokens->touch($api, $request->getClientIp(), new \DateTimeImmutable());
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof ApiTokenException && $exception->getRetryAfter() !== null) {
            return new JsonResponse(['error' => $exception->getError(), 'error_description' => $exception->getMessageKey()], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $exception->getRetryAfter()]);
        }
        // user_checker (обліковий запис вимкнено) та інші винятки — теж 401 invalid_token, без подробиць назовні
        $description = $exception instanceof ApiTokenException ? $exception->getMessageKey() : 'Недійсний токен';
        if (!$exception instanceof ApiTokenException) {
            $this->logger->warning('api_token.auth_failed', ['reason' => 'user_check', 'ip' => self::anonymize($request->getClientIp()), 'message' => $exception->getMessageKey()]);
        }

        return self::unauthorized('invalid_token', $description);
    }

    /** Без заголовка Authorization: 401 з викликом Bearer (RFC 6750 §3). */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized(null, 'Потрібен персональний API-токен: Authorization: Bearer svc_…');
    }

    private function failure(RateLimit $limit, string $ip, string $reason, ?string $prefix, ?AdminApiToken $token, string $description = 'Недійсний токен'): ApiTokenException
    {
        $this->logger->warning('api_token.auth_failed', ['reason' => $reason, 'ip' => self::anonymize($ip), 'prefix' => $prefix, 'token_id' => $token?->getId(), 'user_id' => $token?->getUser()->getId()]);
        if (!$limit->isAccepted()) {
            return ApiTokenException::throttled($limit->getRetryAfter()->getTimestamp() - time());
        }

        return ApiTokenException::invalid($description);
    }

    private static function unauthorized(?string $error, string $description): JsonResponse
    {
        $challenge = sprintf('Bearer realm="%s"', self::REALM);
        if ($error !== null) {
            $challenge .= sprintf(', error="%s", error_description="%s"', $error, str_replace('"', '\'', $description));
        }

        return new JsonResponse(['error' => $error ?? 'unauthorized', 'error_description' => $description], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => $challenge]);
    }

    private static function anonymize(?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : IpUtils::anonymize($ip);
    }
}
