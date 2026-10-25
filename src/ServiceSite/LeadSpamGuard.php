<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Відсів ботів і сканерів у формах заявок (доповнює CSRF і LeadRateLimit).
 *
 * Сканери беруть форму разом із CSRF-токеном і шлють її за долю секунди,
 * підставляючи в поля SQL-проби, теги, довгі ряди цифр. Перевірки:
 *  - honeypot `website` (невидиме поле, людина його не бачить);
 *  - підписана мітка часу `_ft` (Twig `lead_form_ts()`): швидше за MIN_SECONDS
 *    від показу форми людина не заповнює. Поля нема (шаблон паку без нього) -
 *    перевірка пропускається, щоб не зламати старі шаблони; підробка підпису - бот;
 *  - сміття в полях: телефон лише з цифр і розділювачів, ім'я без розмітки,
 *    SQL і посилань, повідомлення без SQL-проб, тегів-посилань і купи URL.
 *
 * Бот отримує звичайну відповідь без помилки (щоб не підбирав обхід), у БД,
 * пошту й CRM нічого не йде; причина - у лог (канал app, `lead.blocked`).
 */
final class LeadSpamGuard
{
    public const TIME_FIELD = '_ft';

    /** Людина не заповнює форму швидше, с. */
    public const MIN_SECONDS = 3;

    private const SQL = '\b(union\s+(all\s+)?select|select\s.+\sfrom|sleep\s*\(|benchmark\s*\(|waitfor\s+delay|information_schema|extractvalue\s*\(|updatexml\s*\(|concat\s*\()';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Значення для прихованого поля `_ft`: час показу форми + підпис. */
    public function formToken(?int $now = null): string
    {
        $t = (string) ($now ?? time());

        return $t . '.' . $this->sign($t);
    }

    /**
     * Причина блокування або null, якщо заявка схожа на людську.
     *
     * @param list<string> $strictFields поля, де не буває розмітки й SQL (ім'я)
     * @param list<string> $textFields   вільний текст (повідомлення)
     */
    public function blockReason(Request $request, array $strictFields = ['name'], array $textFields = ['message'], ?int $now = null): ?string
    {
        $post = $request->request;

        if ((string) $post->get('website', '') !== '') {
            return 'honeypot';
        }

        $ft = (string) $post->get(self::TIME_FIELD, '');

        if ($ft !== '') {
            $parts = explode('.', $ft, 2);

            if (\count($parts) !== 2 || !ctype_digit($parts[0]) || !hash_equals($this->sign($parts[0]), $parts[1])) {
                return 'time-token';
            }

            $elapsed = ($now ?? time()) - (int) $parts[0];

            if ($elapsed < self::MIN_SECONDS) {
                return 'too-fast ' . $elapsed . 's';
            }
        }

        $phone = (string) $post->get('phone', '');

        if ($phone !== '' && !preg_match('/^[0-9+()\s.\-]{1,32}$/', $phone)) {
            return 'junk:phone';
        }

        foreach ($strictFields as $field) {
            $value = (string) $post->get($field, '');

            if ($value !== '' && (preg_match('~[<>{}|;=\\\\]|--|/\*|https?:|www\.|[0-9]{8,}~i', $value) || preg_match('/' . self::SQL . '/i', $value))) {
                return 'junk:' . $field;
            }
        }

        foreach ($textFields as $field) {
            $value = (string) $post->get($field, '');

            if ($value !== '' && (preg_match('/' . self::SQL . '/i', $value)
                || preg_match('~<\s*/?\s*(script|a|iframe|img|svg)\b|\[url[=\]]|\'\s*(or|and)\s+\'?\d~i', $value)
                || preg_match_all('~https?://~i', $value) > 2)) {
                return 'junk:' . $field;
            }
        }

        return null;
    }

    public function logBlocked(Request $request, string $reason, string $form): void
    {
        $this->logger->warning('lead.blocked', [
            'reason' => $reason,
            'form' => $form,
            'host' => $request->getHost(),
            'ip' => $request->getClientIp(),
            'ua' => mb_substr((string) $request->headers->get('user-agent', ''), 0, 160),
            'name' => mb_substr((string) $request->request->get('name', ''), 0, 80),
        ]);
    }

    private function sign(string $t): string
    {
        return substr(hash_hmac('sha256', 'lead-ft|' . $t, $this->secret), 0, 20);
    }
}
