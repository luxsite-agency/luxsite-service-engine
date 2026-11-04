<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Partners;

use App\AdminApi\ImageFetcher;
use App\Entity\ServicePartner;
use App\Settings\SecretBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebhookSender
{
    public function __construct(
        #[Autowire(service: 'http_client')] private readonly HttpClientInterface $http,
        private readonly HostResolver $resolver,
        private readonly SecretBox $box,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function send(ServicePartner $partner, array $payload): void
    {
        try {
            $url = $partner->getWebhookUrl() ?? '';
            $parts = parse_url($url);
            $host = strtolower(trim($parts['host'] ?? '', '[]'));
            if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || $host === '') {
                throw new PartnerDeliveryException('Вебхук: дозволено лише https://');
            }
            if (isset($parts['user']) || isset($parts['pass'])) {
                throw new PartnerDeliveryException('Вебхук: логін і пароль у URL заборонені');
            }
            if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
                throw new PartnerDeliveryException('Вебхук: локальний хост заборонений');
            }
            $ips = $this->resolver->ips($host);
            if ($ips === []) {
                throw new PartnerDeliveryException('Вебхук: хост не резолвиться');
            }
            foreach ($ips as $ip) {
                if (ImageFetcher::isBlocked($ip)) {
                    throw new PartnerDeliveryException('Вебхук: приватні й локальні IP заборонені');
                }
            }
            $body = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            $timestamp = (string) time();
            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => ($payload['site']['name'] ?? 'Leads') . ' lead webhook',
                'X-Lead-Event' => $payload['event'],
                'X-Lead-Delivery' => (string) ($payload['assignment_id'] ?? 'ping'),
                'X-Lead-Timestamp' => $timestamp,
            ];
            if ($partner->getWebhookSecret() !== null) {
                // Нечитабельний секрет — помилка доставки, а не непідписаний запит.
                $secret = $this->box->decrypt($partner->getWebhookSecret());
                $headers['X-Lead-Signature'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
            }
            $response = $this->http->request('POST', $url, [
                'body' => $body, 'headers' => $headers,
                'resolve' => [$host => $ips[0]],
                'max_redirects' => 0, 'timeout' => 5, 'max_duration' => 5,
            ]);
            try {
                $status = $response->getStatusCode();
            } finally {
                // Потрібен лише статус: не завантажуємо довільне тіло відповіді партнера.
                $response->cancel();
            }
            if ($status < 200 || $status >= 300) {
                throw new PartnerDeliveryException('HTTP ' . $status);
            }
        } catch (\Throwable $e) {
            throw new PartnerDeliveryException(mb_substr($e->getMessage(), 0, 500), 0, $e);
        }
    }
}
