<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceLead;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Email-сповіщення про нову заявку на адреси міста. Best effort: заявка
 * вже в БД (черга в адмінці), падіння SMTP її не втрачає. Спільний для
 * LeadController (проста форма) і CalculatorController (заявка з розрахунком).
 */
final class LeadNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        // Gmail SMTP вимагає from = автентифікований акаунт; порожньо = noreply@апекс бренду
        #[Autowire(env: 'MAIL_FROM')]
        private readonly string $mailFrom = '',
    ) {
    }

    /**
     * @param string $subjectPrefix «Заявка» або «Заявка з калькулятора»
     * @param string $fallbackHost хост запиту, коли в бренду нема апекс-домену
     */
    public function notify(ServiceLead $lead, string $subjectPrefix, string $fallbackHost): void
    {
        $city = $lead->getCity();
        $leadTo = $city?->getLeadEmails() ?? $city?->getEmail();
        if ($city === null || $leadTo === null) {
            return;
        }

        // у subject не пускаємо CR/LF і хвости (Mime кодує, але вхід чистимо самі)
        // форма зв'язку контентного сайту (22.10.2026): замість телефону — email; заявки сервісних сайтів — як були
        $email = $lead->getEmail();
        $subjectPhone = mb_substr((string) preg_replace('/\s+/', ' ', $email ?? $lead->getPhone()), 0, $email !== null ? 190 : 32);
        // кілька адресатів через кому (пересилка в кілька скриньок)
        $recipients = array_filter(array_map('trim', explode(',', $leadTo)));
        if ($recipients === []) {
            $this->logger->warning('Заявка без адресатів: у міста порожні lead_emails і email.');

            return;
        }

        try {
            $fromDomain = $city->getSite()?->getApexDomain() ?? $fallbackHost;
            $from = $this->mailFrom !== '' ? $this->mailFrom : 'noreply@' . $fromDomain;
            $message = (new Email())
                ->from(new Address($from, $city->getSite()?->getName() ?? 'Заявки'))
                ->to(...$recipients)
                ->subject(sprintf('%s з %s: %s (%s)', $subjectPrefix, $city->getHost(), $subjectPhone, $city->getNameNomUk()));
            if ($email !== null) {
                $message->replyTo($email);
            }
            $this->mailer->send($message
                ->text(sprintf(
                    ($email !== null ? 'Email: ' . $email . "\n" : '') . "Телефон: %s\nІм'я: %s\nПовідомлення:\n%s\n\nДжерело: %s\nСторінка: %s\nМісто: %s",
                    $lead->getPhone() !== '' ? $lead->getPhone() : '-',
                    $lead->getName() ?? '-',
                    $lead->getMessage() ?? '-',
                    LeadAttribution::summary($lead),
                    self::pageLink($lead, $city->getHost()),
                    $city->getHost(),
                )));
        } catch (\Throwable $e) {
            // заявка вже в БД (черга в адмінці) - мейл лише дублює
            $this->logger->error('Не вдалося надіслати мейл заявки: ' . $e->getMessage(), ['lead_id' => $lead->getId()]);
        }
    }

    /** Повне посилання на сторінку заявки: у пошті відносний шлях некликабельний. */
    private static function pageLink(ServiceLead $lead, string $host): string
    {
        $path = $lead->getPageUrl();
        if ($path === null || $path === '') {
            return '-';
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return 'https://' . $host . $path;
    }
}
