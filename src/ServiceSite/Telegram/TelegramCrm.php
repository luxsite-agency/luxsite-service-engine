<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Telegram;

use App\Entity\ServiceLead;
use App\ServiceSite\Crm\AbstractLeadCrm;
use App\ServiceSite\Crm\CrmException;
use App\ServiceSite\Crm\LeadCrmCapabilities;
use App\ServiceSite\LeadAttribution;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Конектор Telegram (app.crm.system: telegram): заявка -> повідомлення в чат через Bot API sendMessage.
 * Це сповіщення, а не CRM: crm_external_id = message_id, дедуплікації контактів нема.
 *
 * Доступи - лише .env.local сайту: TELEGRAM_BOT_TOKEN (від @BotFather) і TELEGRAM_CHAT_ID (особа, група, канал; для
 * груп/каналів - від'ємний id). Порожній будь-який із двох = вимкнено. Токен - секрет: він у URL запиту, тому
 * HTTP-клієнт - без логера (app.crm_http_client), а з повідомлень винятків токен прибирає redact().
 */
final class TelegramCrm extends AbstractLeadCrm
{
    public const SYSTEM = 'telegram';

    private const API = 'https://api.telegram.org/bot';
    /** Ліміт Bot API на текст повідомлення - 4096 символів */
    private const MAX_LENGTH = 4000;

    public function __construct(
        #[Autowire(service: 'app.crm_http_client')]
        private readonly HttpClientInterface $http,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        #[Autowire(env: 'TELEGRAM_BOT_TOKEN')]
        private readonly string $token = '',
        #[Autowire(env: 'TELEGRAM_CHAT_ID')]
        private readonly string $chatId = '',
    ) {
        parent::__construct($em, $logger);
    }

    public function system(): string
    {
        return self::SYSTEM;
    }

    public function isEnabled(): bool
    {
        return trim($this->token) !== '' && trim($this->chatId) !== '';
    }

    public function capabilities(): LeadCrmCapabilities
    {
        return new LeadCrmCapabilities(pushLead: true);
    }

    protected function send(ServiceLead $lead): string
    {
        try {
            $response = $this->http->request('POST', self::API . trim($this->token) . '/sendMessage', [
                'json' => [
                    'chat_id' => trim($this->chatId),
                    'text' => $this->text($lead),
                    'disable_web_page_preview' => true,
                ],
                'timeout' => 10,
                'max_duration' => 15,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new CrmException('Telegram sendMessage: ' . $this->redact($e->getMessage()));
        }

        $messageId = $body['result']['message_id'] ?? null;
        if (($body['ok'] ?? false) !== true || !\is_int($messageId)) {
            throw new CrmException($this->redact(sprintf(
                'Telegram sendMessage: HTTP %d %s',
                $status,
                \is_scalar($body['description'] ?? null) ? (string) $body['description'] : 'без message_id',
            )));
        }

        return (string) $messageId;
    }

    /** Звичайний текст без parse_mode: дані клієнта не треба екранувати, розмітка в них не спрацює. */
    private function text(ServiceLead $lead): string
    {
        $host = $lead->getCity()?->getHost() ?? '';
        $page = $lead->getPageUrl();
        $pageUrl = match (true) {
            $page === null || $page === '' => null,
            str_starts_with($page, 'http://'), str_starts_with($page, 'https://') => $page,
            default => 'https://' . $host . '/' . ltrim($page, '/'),
        };
        $message = trim((string) $lead->getMessage());

        $rows = array_filter([
            'Нова заявка' . ($host !== '' ? ' · ' . $host : '') . ($lead->getId() !== null ? ' · #' . $lead->getId() : ''),
            $lead->getName() !== null && $lead->getName() !== '' ? "Ім'я: " . $lead->getName() : null,
            'Телефон: ' . $lead->getPhone(),
            $lead->getService() !== null && $lead->getService() !== '' ? 'Послуга: ' . $lead->getService() : null,
            $pageUrl !== null ? 'Сторінка: ' . $pageUrl : null,
            'Джерело: ' . LeadAttribution::summary($lead),
            $lead->getClickId() !== null ? 'gclid: ' . $lead->getClickId() : null,
        ]);
        array_push($rows, ...$this->detailLines($lead));
        if ($message !== '') {
            $rows[] = '';
            $rows[] = $message;
        }

        return mb_substr(implode("\n", $rows), 0, self::MAX_LENGTH);
    }

    /** Токен бота - секрет: прибираємо його з будь-якого тексту, що йде в лог чи адмінку (він у URL запиту). */
    private function redact(string $text): string
    {
        $token = trim($this->token);
        if ($token !== '') {
            $text = str_replace($token, '<TELEGRAM_BOT_TOKEN>', $text);
        }

        return (string) preg_replace('#/bot\d+:[A-Za-z0-9_-]+#', '/bot<token>', $text);
    }
}
