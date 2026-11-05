<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Обов'язковий чекбокс згоди на обробку персональних даних у формах заявки (app.lead_consent паку):
 *
 *   app.lead_consent:
 *       enabled: true
 *       text: { uk: 'Погоджуюсь на обробку персональних даних згідно з {policy}', ru: '…' }   # {policy} — посилання
 *       policy_text: { uk: політикою конфіденційності, ru: политикой конфиденциальности }
 *       policy_url: { uk: /privacy-policy/, ru: /ru/privacy-policy/ }                          # або повна адреса
 *
 * Порожні text/policy_text — переклади рушія lead.consent / lead.consent_policy, порожній policy_url — сторінка
 * sv_privacy. Вимкнено (дефолт) — форми як були. Увімкнено — чекбокс у формі заявки й калькулятора, сервер без нього
 * заявку не приймає, час згоди — ServiceLead.consentAt. Свої форми паку мають вставити
 * services/partials/_lead_consent.html.twig (у _lead_fields.html.twig він уже є).
 */
final class LeadConsent
{
    private readonly bool $enabled;
    /** @var array<string, array{uk: string, ru: string, en: string}> */
    private readonly array $texts;

    /** @param array<string, mixed> $config app.lead_consent */
    public function __construct(
        #[Autowire('%app.lead_consent%')]
        array $config = [],
    ) {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $texts = [];
        foreach (['text', 'policy_text', 'policy_url'] as $k) {
            $v = $config[$k] ?? [];
            $v = \is_scalar($v) ? ['uk' => (string) $v] : (\is_array($v) ? $v : []);
            $en = trim((string) ($v['en'] ?? ''));
            $uk = trim((string) ($v['uk'] ?? '')) ?: $en;
            $texts[$k] = ['uk' => $uk, 'ru' => trim((string) ($v['ru'] ?? '')) ?: $uk, 'en' => $en !== '' ? $en : $uk];
        }
        $this->texts = $texts;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Тексти з паку мовою сторінки; порожній рядок — шаблон бере дефолт рушія. null — згоду вимкнено.
     *
     * @return array{text: string, policy_text: string, policy_url: string}|null
     */
    public function forForm(string $locale): ?array
    {
        if (!$this->enabled) {
            return null;
        }
        $lang = \in_array($locale, ['ru', 'en'], true) ? $locale : 'uk';

        return [
            'text' => $this->texts['text'][$lang],
            'policy_text' => $this->texts['policy_text'][$lang],
            'policy_url' => $this->texts['policy_url'][$lang],
        ];
    }

    /** Чи прийшла згода з форми (чекбокс name="consent" value="1"); вимкнено — завжди так. */
    public function accepted(mixed $value): bool
    {
        return !$this->enabled || $value === '1' || $value === 1 || $value === true || $value === 'on';
    }

    /** Знімок показаного тексту без HTML, з тими самими фолбеками, що й у формі. */
    public function textForRecord(string $locale, TranslatorInterface $translator): ?string
    {
        $form = $this->forForm($locale);
        if ($form === null) {
            return null;
        }
        $text = $form['text'] !== '' ? $form['text'] : $translator->trans('lead.consent', [], 'servicesite', $locale);
        $policy = $form['policy_text'] !== '' ? $form['policy_text'] : $translator->trans('lead.consent_policy', [], 'servicesite', $locale);
        $parts = explode('{policy}', $text, 2);

        return $parts[0] . (\count($parts) === 1 ? ' ' : '') . $policy . ($parts[1] ?? '');
    }
}
