<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceCity;
use App\Entity\ServiceLead;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Спам не губиться: заявку, яку відсіяв LeadSpamGuard, зберігаємо зі spam_reason (поля як прийшли, обрізані до
 * колонок; без файлів), щоб в адмінці було видно обсяг і можна було повернути хибне спрацювання дією «Не спам».
 * У пошту, CRM і сповіщення такий запис не йде (LeadDispatcher не викликається). Ліміт — app.lead_spam.ip_per_hour
 * записів з IP за годину (LeadRateLimit), далі лише лог lead.blocked.
 */
final class LeadSpamRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeadRateLimit $rateLimit,
        private readonly LeadSpamGuard $guard,
        private readonly LeadAttribution $attribution,
    ) {
    }

    /** @return ServiceLead|null збережений запис або null, коли ліміт записів з IP вичерпано */
    public function record(Request $request, ServiceCity $city, string $reason, string $form, string $pageUrl, string $locale): ?ServiceLead
    {
        $stored = $this->rateLimit->acceptsSpamRecord($request);
        $this->guard->logBlocked($request, $reason, $form, $stored);
        if (!$stored) {
            return null;
        }

        $post = $request->request;
        $lead = (new ServiceLead())
            ->setCity($city)
            ->setPhone(mb_substr(self::text($post->get('phone')), 0, 32))
            ->setName(self::nullable(mb_substr(self::text($post->get('name')), 0, 128)))
            ->setMessage(self::nullable(mb_substr(self::text($post->get('message')), 0, 3000)))
            ->setService(self::nullable(mb_substr(self::text($post->get('service')), 0, 255)))
            ->setContext(self::nullable(mb_substr(self::text($post->get('context')), 0, 255)))
            ->setPageUrl(mb_substr($pageUrl, 0, 512))
            ->setLocale($locale)
            ->setSpamReason(mb_substr($reason, 0, 120));
        $this->attribution->apply($request, $lead);
        $this->em->persist($lead);
        $this->em->flush();

        return $lead;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    private static function nullable(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
