<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Ліміт заявок за IP з винятком для своїх адрес.
 *
 * Ліміт — app.lead_spam.ip_per_hour (типово 5 за годину, ковзне вікно; 0 = без ліміту), тому лімітер будується тут,
 * а не в framework.rate_limiter (вкладений параметр паку туди не підставити). Той самий ліміт діє окремо на
 * спам-записи з IP (LeadSpamRecorder): далі спам лише в лог, щоб бот не роздував таблицю.
 *
 * Тестування з офісу в ліміт упиралось: чотири перевірочні заявки поспіль - і форма вже відповідає «забагато».
 * Список винятків - в LEAD_RATE_LIMIT_ALLOWLIST, приймає і окремі адреси, і підмережі
 * (`2a0c:5a82:1102:1300::/64`, `203.0.113.0/24`).
 */
final class LeadRateLimit
{
    private readonly int $limit;
    private ?RateLimiterFactory $leads = null;
    private ?RateLimiterFactory $spam = null;

    /** @param array<string, mixed> $config app.lead_spam */
    public function __construct(
        #[Autowire(param: 'app.lead_spam')]
        array $config,
        #[Autowire(service: 'cache.rate_limiter')]
        private readonly CacheItemPoolInterface $pool,
        #[Autowire(env: 'LEAD_RATE_LIMIT_ALLOWLIST')]
        private readonly string $allowlist = '',
    ) {
        $this->limit = LeadSpamGuard::normalize($config)['ip_per_hour'];
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /** Чи приймати заявку з цієї IP (витрачає одиницю ліміту). */
    public function isAccepted(Request $request): bool
    {
        return $this->consume($request, $this->leads ??= $this->factory('service_lead_ip'));
    }

    /** Чи зберігати ще один спам-запис з цієї IP за годину (витрачає одиницю окремого ліміту). */
    public function acceptsSpamRecord(Request $request): bool
    {
        return $this->consume($request, $this->spam ??= $this->factory('service_lead_spam_ip'));
    }

    private function consume(Request $request, RateLimiterFactory $factory): bool
    {
        if ($this->limit <= 0) {
            return true;
        }
        $ip = $request->getClientIp();

        if ($ip !== null && $this->isAllowlisted($ip)) {
            return true;
        }

        return $factory->create($ip ?? 'unknown')->consume()->isAccepted();
    }

    private function factory(string $id): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => max(1, $this->limit), 'interval' => '60 minutes'],
            new CacheStorage($this->pool),
        );
    }

    private function isAllowlisted(string $ip): bool
    {
        $ranges = array_filter(array_map('trim', explode(',', $this->allowlist)));

        return $ranges !== [] && IpUtils::checkIp($ip, $ranges);
    }
}
