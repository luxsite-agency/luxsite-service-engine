<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceLead;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Сповіщення про нову заявку в Telegram-групу клініки (Bot API sendMessage).
 *
 * Токен бота і id групи - у .env.local сайту; порожні = вимкнено (dev, локальний
 * стек, сайти без бота). Best effort, як і мейл: заявка вже в БД, падіння
 * Telegram її не втрачає. Клієнт без логера (app.crm_http_client): токен у URL.
 */
final class TelegramLeadNotifier
{
    /** Форма чекає на відповідь, тож довго не тримаємо. */
    private const TIMEOUT = 4.0;

    public function __construct(
        #[Autowire(service: 'app.crm_http_client')]
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'TELEGRAM_BOT_TOKEN')]
        private readonly string $token = '',
        #[Autowire(env: 'TELEGRAM_LEAD_CHAT_ID')]
        private readonly string $chatId = '',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }

    public function notify(ServiceLead $lead, string $subjectPrefix, string $pageLink): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $response = $this->http->request('POST', 'https://api.telegram.org/bot' . $this->token . '/sendMessage', [
                'json' => [
                    'chat_id' => $this->chatId,
                    'text' => self::text($lead, $subjectPrefix, $pageLink),
                    'parse_mode' => 'HTML',
                    'link_preview_options' => ['is_disabled' => true],
                ],
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
            ]);
            if ($response->getStatusCode() !== 200) {
                // тіло без токена: Bot API повертає лише description
                $this->logger->error('Telegram не прийняв заявку: ' . mb_substr($response->getContent(false), 0, 300), ['lead_id' => $lead->getId()]);
            }
        } catch (\Throwable $e) {
            // текст винятку HttpClient містить URL з токеном - у журнал лише клас
            $this->logger->error('Не вдалося надіслати заявку в Telegram: ' . $e::class, ['lead_id' => $lead->getId()]);
        }
    }

    /**
     * Ім'я, телефон, поля форми (уже в кінці message рядками «Поле: значення»), сторінка.
     * Телефон - окремим рядком у форматі +380…: клієнти Telegram роблять його натискним.
     */
    public static function text(ServiceLead $lead, string $subjectPrefix, string $pageLink): string
    {
        $e = static fn (?string $s): string => htmlspecialchars((string) $s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        $lines = [
            '<b>' . $e($subjectPrefix) . '</b>',
            '',
            "Ім'я: " . $e($lead->getName() ?? '-'),
            'Телефон: ' . $e(self::phone($lead->getPhone())),
        ];
        $message = trim((string) $lead->getMessage());
        if ($message !== '') {
            $lines[] = $e($message);
        }
        $lines[] = '';
        $lines[] = 'Сторінка: ' . $e($pageLink);

        return mb_substr(implode("\n", $lines), 0, 4000);
    }

    /** 0965593399 / 380965593399 → +380965593399; інше лишаємо як ввели. */
    public static function phone(string $raw): string
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if (\strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '+38' . $digits;
        }
        if (\strlen($digits) === 12 && str_starts_with($digits, '380')) {
            return '+' . $digits;
        }

        return trim($raw);
    }
}
