<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceApplication;
use App\Settings\SiteSettingKeys;
use App\Settings\SiteSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Лист про нову анкету — на «Адресу для анкет» сайту (адмінка → Налаштування, forms.applications_email), інакше на email
 * форми з паку; без жодної — лише запис у адмінці й попередження в лозі. Не на адреси заявок міста: анкета — не клієнт.
 * Best effort, як LeadNotifier: анкета вже в БД. Файли — посиланнями на адмінку.
 */
final class ApplicationNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly SiteSettings $settings,
        private readonly Applications $applications,
        private readonly LeadFiles $files,
        #[Autowire(env: 'MAIL_FROM')]
        private readonly string $mailFrom = '',
    ) {
    }

    public function notify(ServiceApplication $application, string $fallbackHost): void
    {
        $city = $application->getCity();
        $site = $city?->getSite();
        if ($city === null || $site === null) {
            return;
        }
        $to = $this->settings->valid($site, SiteSettingKeys::APPLICATIONS_EMAIL) ?: $this->applications->email($application->getForm());
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) $to))));
        if ($recipients === []) {
            $this->logger->warning('Анкета {form} без адресата: порожні «Адреса для анкет» і email форми — лише в адмінці.', ['form' => $application->getForm()]);

            return;
        }

        $rows = [
            "Ім'я: " . ($application->getName() ?? '-'),
            'Телефон: ' . ($application->getPhone() ?? '-'),
            'Email: ' . ($application->getEmail() ?? '-'),
        ];
        foreach ($application->getData() as $row) {
            $rows[] = $row['label'] . ': ' . $row['value'];
        }
        $fileRows = $this->files->lines($application->getFiles());
        if ($fileRows !== []) {
            $rows[] = '';
            $rows[] = 'Файли (відкриваються після входу в адмінку):';
            array_push($rows, ...$fileRows);
        }
        $rows[] = '';
        $rows[] = 'Згода на обробку даних: ' . ($application->hasConsent() ? 'так' : 'ні');
        $page = (string) $application->getPageUrl();
        $rows[] = 'Сторінка: ' . ($page === '' ? '-' : (str_starts_with($page, 'http') ? $page : 'https://' . $city->getHost() . $page));

        try {
            $from = $this->mailFrom !== '' ? $this->mailFrom : 'noreply@' . ($site->getApexDomain() ?? $fallbackHost);
            $subjectName = mb_substr((string) preg_replace('/\s+/', ' ', (string) ($application->getName() ?? $application->getPhone() ?? '')), 0, 64);
            $this->mailer->send((new Email())
                ->from(new Address($from, $site->getName() ?? 'Анкети'))
                ->to(...$recipients)
                ->subject(sprintf('%s з %s: %s', $this->applications->title($application->getForm(), 'uk'), $city->getHost(), $subjectName))
                ->text(implode("\n", $rows)));
        } catch (\Throwable $e) {
            $this->logger->error('Не вдалося надіслати лист анкети: ' . $e->getMessage(), ['application_id' => $application->getId()]);
        }
    }
}
