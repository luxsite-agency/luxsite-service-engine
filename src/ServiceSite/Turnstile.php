<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cloudflare Turnstile у формах заявок — опційно (app.lead_spam.turnstile). Увімкнено, лише коли пак задав site_key, а в
 * .env.local є секрет у змінній secret_env (типово TURNSTILE_SECRET): тоді форми рушія рендерять віджет
 * (partials/_turnstile.html.twig, скрипт з defer у base), а LeadSpamGuard перевіряє відповідь на siteverify
 * (таймаут 3 с). Cloudflare недоступний — заявку пропускаємо (warning у лог): захист не має губити клієнтів.
 * Без ключів нічого не рендериться й не перевіряється.
 */
final class Turnstile
{
    /** Поле, яке віджет додає у форму. */
    public const FIELD = 'cf-turnstile-response';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const TIMEOUT = 3.0;

    private readonly string $siteKey;
    private readonly string $secretEnv;

    /** @param array<string, mixed> $config app.lead_spam */
    public function __construct(
        #[Autowire(param: 'app.lead_spam')]
        array $config,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
        $c = LeadSpamGuard::normalize($config)['turnstile'];
        $this->siteKey = $c['site_key'];
        $this->secretEnv = $c['secret_env'];
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    /** Віджет і перевірка працюють лише з обома ключами. */
    public function enabled(): bool
    {
        return $this->siteKey !== '' && $this->secret() !== '';
    }

    /** Причина блокування (turnstile:missing | turnstile:failed) або null — пройшло чи перевірити не вдалося. */
    public function verify(Request $request): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        $token = (string) $request->request->get(self::FIELD, '');
        if ($token === '') {
            return 'turnstile:missing';
        }

        try {
            $data = $this->http->request('POST', self::VERIFY_URL, [
                'body' => ['secret' => $this->secret(), 'response' => mb_substr($token, 0, 2048), 'remoteip' => (string) $request->getClientIp()],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Turnstile: перевірити не вдалося, заявку пропущено: ' . $e->getMessage(), ['host' => $request->getHost()]);

            return null;
        }
        if (($data['success'] ?? false) === true) {
            return null;
        }
        $codes = $data['error-codes'] ?? [];
        // збій на боці Cloudflare (не відповідь бота) — пропускаємо, як і недоступність
        if (\is_array($codes) && \in_array('internal-error', $codes, true)) {
            $this->logger->warning('Turnstile: internal-error, заявку пропущено', ['host' => $request->getHost()]);

            return null;
        }

        return 'turnstile:failed';
    }

    private function secret(): string
    {
        if ($this->secretEnv === '') {
            return '';
        }
        $value = $_SERVER[$this->secretEnv] ?? $_ENV[$this->secretEnv] ?? getenv($this->secretEnv);

        return \is_string($value) ? trim($value) : '';
    }
}
