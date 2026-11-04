<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Partners;

use App\Entity\ServiceLead;
use App\Entity\ServiceLeadAssignment;
use App\ServiceSite\LeadFields;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Листи дублюють збережену передачу; помилка пошти не скасовує заявку. */
final class PartnerNotifier
{
    public function __construct(
        private readonly PartnersConfig $config,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly LeadFields $fields,
        private readonly EntityManagerInterface $em,
        #[Autowire(env: 'MAIL_FROM')] private readonly string $mailFrom = '',
    ) {
    }

    public function notify(ServiceLeadAssignment $assignment): void
    {
        if (!$this->config->enabled || $assignment->getDeliveredAt() !== null) {
            return;
        }
        $lead = $assignment->getLead();
        $to = self::recipients($assignment->getPartner()?->getNotifyEmails());
        if ($lead === null || $to === []) {
            return;
        }
        $consent = $lead->getConsentText() ?? '-';
        if ($lead->getConsentAt() !== null) {
            $consent .= ' (' . $lead->getConsentAt()->setTimezone($this->config->timezone)->format('Y-m-d H:i:s T') . ')';
        }
        try {
            $this->mailer->send($this->email($lead)->to(...$to)
                ->subject(sprintf('New lead #%s: %s → %s', $lead->getId(), self::subjectLine($lead->getZipFrom() ?? '-'), self::subjectLine($lead->getZipTo() ?? '-')))
                ->text(sprintf("Phone: %s\nName: %s\nFrom ZIP: %s\nTo ZIP: %s\nRequest:\n%s\nConsent: %s",
                    $lead->getPhone(), $lead->getName() ?? '-', $lead->getZipFrom() ?? '-', $lead->getZipTo() ?? '-',
                    $this->requestText($lead), $consent)));
        } catch (\Throwable $e) {
            $this->logger->error('Не вдалося надіслати заявку партнеру: ' . $e->getMessage(), ['assignment_id' => $assignment->getId(), 'lead_id' => $lead->getId()]);

            return;
        }
        $assignment->setDeliveredAt(new \DateTimeImmutable());
        $this->em->flush();
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
