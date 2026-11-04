<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Partners;

use App\Entity\ServiceLead;
use App\Entity\ServiceLeadAssignment;
use App\Entity\ServicePartner;
use App\ServiceSite\LeadFields;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Кожен канал доставляється окремо; записи передач є чергою повторів для cron. */
final class PartnerNotifier
{
    public const int MAX_ATTEMPTS = 6;

    public function __construct(
        private readonly PartnersConfig $config,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly LeadFields $fields,
        private readonly EntityManagerInterface $em,
        private readonly WebhookSender $webhooks,
        #[Autowire(env: 'MAIL_FROM')] private readonly string $mailFrom = '',
    ) {
    }

    public function notify(ServiceLeadAssignment $assignment): void
    {
        // нема каналів (ні email, ні вебхука) або все вже доставлено — нічого не пишемо в БД
        if (!$this->config->enabled
            || ($assignment->pendingChannels() === [] && $assignment->getPartner()?->getStatus() !== ServicePartner::STATUS_LEFT)) {
            return;
        }
        try {
            $now = new \DateTimeImmutable();
            if ($assignment->getPartner()?->getStatus() === ServicePartner::STATUS_LEFT) {
                $assignment->setNextAttemptAt(null)->setLastError('компанія вибула');
            } else {
                $lastError = null;
                foreach ($assignment->pendingChannels() as $channel) {
                    try {
                        if ($channel === 'email') {
                            $this->sendEmail($assignment);
                            $assignment->setDeliveredAt(new \DateTimeImmutable());
                        } else {
                            $this->webhooks->send($assignment->getPartner(), $this->payload($assignment));
                            $assignment->setWebhookDeliveredAt(new \DateTimeImmutable());
                        }
                    } catch (\Throwable $e) {
                        $lastError = mb_substr($e->getMessage(), 0, 500);
                        $this->logger->error('Не вдалося надіслати заявку партнеру: ' . $lastError,
                            ['assignment_id' => $assignment->getId(), 'channel' => $channel]);
                    }
                }
                if ($assignment->isFullyDelivered()) {
                    $assignment->setLastError(null)->setNextAttemptAt(null);
                } else {
                    $attempts = $assignment->getAttempts() + 1;
                    $assignment->setAttempts($attempts)->setLastError($lastError);
                    $delay = [1 => '+5 minutes', 2 => '+15 minutes', 3 => '+1 hour', 4 => '+3 hours', 5 => '+6 hours'];
                    $assignment->setNextAttemptAt($attempts >= self::MAX_ATTEMPTS ? null : $now->modify($delay[$attempts]));
                }
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Не вдалося зберегти доставку партнеру: ' . mb_substr($e->getMessage(), 0, 500),
                ['assignment_id' => $assignment->getId()]);
        }
    }

    /** @return array<string, mixed> */
    public function payload(ServiceLeadAssignment $assignment): array
    {
        $lead = $assignment->getLead() ?? throw new PartnerDeliveryException('У передачі немає заявки');

        return [
            'event' => 'lead.assigned', 'assignment_id' => $assignment->getId(), 'lead_id' => $lead->getId(),
            'assigned_at' => $assignment->getAssignedAt()->format(\DateTimeInterface::ATOM),
            'position' => $assignment->getPosition(), 'price_cents' => $assignment->getPrice(),
            'site' => $this->site($assignment->getPartner()),
            'lead' => [
                'phone' => $lead->getPhone(), 'name' => $lead->getName(),
                'zip_from' => $lead->getZipFrom(), 'zip_to' => $lead->getZipTo(),
                'details' => (object) ($lead->getDetails() ?? []), 'message' => $lead->getMessage(),
                'page_url' => $lead->getPageUrl(), 'locale' => $lead->getLocale(),
                'consent' => ['text' => $lead->getConsentText(), 'at' => $lead->getConsentAt()?->format(\DateTimeInterface::ATOM)],
            ],
        ];
    }

    public function ping(ServicePartner $partner): ?string
    {
        if (!$this->config->enabled) {
            return 'Модуль партнерів вимкнено';
        }
        if ($partner->getStatus() === ServicePartner::STATUS_LEFT) {
            return 'компанія вибула';
        }
        try {
            $this->webhooks->send($partner, ['event' => 'ping', 'site' => $this->site($partner),
                'sent_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)]);

            return null;
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 500);
            $this->logger->error('Перевірка вебхука не вдалася: ' . $error, ['partner_id' => $partner->getId()]);

            return $error;
        }
    }

    /** @return array{name: string, host: ?string} */
    private function site(?ServicePartner $partner): array
    {
        $city = $partner?->getCity();

        return ['name' => $city?->getSite()?->getName() ?? 'Leads', 'host' => $city?->getHost()];
    }

    private function sendEmail(ServiceLeadAssignment $assignment): void
    {
        $lead = $assignment->getLead();
        $to = self::recipients($assignment->getPartner()?->getNotifyEmails());
        if ($lead === null || $to === []) {
            throw new PartnerDeliveryException('Немає заявки або адрес email');
        }
        $consent = $lead->getConsentText() ?? '-';
        if ($lead->getConsentAt() !== null) {
            $consent .= ' (' . $lead->getConsentAt()->setTimezone($this->config->timezone)->format('Y-m-d H:i:s T') . ')';
        }
        $this->mailer->send($this->email($lead)->to(...$to)
                ->subject(sprintf('New lead #%s: %s → %s', $lead->getId(), self::subjectLine($lead->getZipFrom() ?? '-'), self::subjectLine($lead->getZipTo() ?? '-')))
                ->text(sprintf("Phone: %s\nName: %s\nFrom ZIP: %s\nTo ZIP: %s\nRequest:\n%s\nConsent: %s",
                    $lead->getPhone(), $lead->getName() ?? '-', $lead->getZipFrom() ?? '-', $lead->getZipTo() ?? '-',
                    $this->requestText($lead), $consent)));
    }

    public function notifyUnrouted(ServiceLead $lead): void
    {
        if (!$this->config->enabled || !$this->config->unroutedNotify) {
            return;
        }
        $city = $lead->getCity();
        $to = self::recipients($city?->getLeadEmails() ?? $city?->getEmail());
        if ($city === null || $to === []) {
            return;
        }
        try {
            $this->mailer->send($this->email($lead)->to(...$to)
                ->subject(sprintf('Заявка без виконавця #%s (%s)', $lead->getId(), self::subjectLine($city->getNameNomUk())))
                ->text(sprintf("Телефон: %s\nІм'я: %s\nZIP: %s → %s\n%s", $lead->getPhone(), $lead->getName() ?? '-',
                    $lead->getZipFrom() ?? '-', $lead->getZipTo() ?? '-', $this->requestText($lead))));
        } catch (\Throwable $e) {
            $this->logger->error('Не вдалося надіслати сповіщення про заявку без виконавця: ' . $e->getMessage(), ['lead_id' => $lead->getId()]);
        }
    }

    private function email(ServiceLead $lead): Email
    {
        $city = $lead->getCity();
        $domain = $city?->getSite()?->getApexDomain() ?: $city?->getHost();

        return (new Email())->from(new Address($this->mailFrom !== '' ? $this->mailFrom : 'noreply@' . $domain, $city?->getSite()?->getName() ?? 'Leads'));
    }

    /**
     * Текст заявки без дубля полів: форма вже дописала поля паку («Поле: значення») у кінець повідомлення; заявка без
     * повідомлення (калькулятор, API) — лише поля.
     */
    private function requestText(ServiceLead $lead): string
    {
        $message = trim((string) $lead->getMessage());
        $summary = $this->fields->summary($lead->getDetails() ?? []);
        if ($summary !== '' && !str_contains($message, $summary)) {
            $message = trim($message . "\n\n" . $summary);
        }

        return $message !== '' ? $message : '-';
    }

    /** @return list<string> */
    private static function recipients(?string $emails): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $emails ?? ''))));
    }

    private static function subjectLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
