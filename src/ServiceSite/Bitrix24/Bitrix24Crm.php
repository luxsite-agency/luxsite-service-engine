<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Bitrix24;

use App\Entity\ServiceLead;
use App\Repository\ServiceItemRepository;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\Crm\AbstractLeadCrm;
use App\ServiceSite\Crm\CrmException;
use App\ServiceSite\Crm\LeadCrmCapabilities;
use App\ServiceSite\LeadAttribution;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Конектор Bitrix24 (app.crm.system: bitrix24): заявка -> crm.contact.add -> crm.deal.add з CONTACT_ID.
 * Логіка - як у типовому Joomla-обробнику форми (контакт, потім угода з прив'язкою до контакту).
 *
 * Доступ - вхідний вебхук порталу клієнта, лише з .env.local сайту: BITRIX24_WEBHOOK_URL =
 * https://<портал>/rest/<id>/<токен>/ (порожньо = вимкнено, стенд у чужу CRM не пише). Адреса вебхука - секрет:
 * у лог, crm_error і повідомлення винятків вона не потрапляє (redact()), HTTP-клієнт - без логера.
 *
 * Рушій не знає UF-кодів клієнта: усе акаунтне (CATEGORY_ID, STAGE_ID, кастомні UF_CRM_* поля контакту й угоди,
 * константи) - у паку, app.crm.bitrix24, як мапа «поле Bitrix => джерело». Джерело - див. SOURCES; рядок із
 * {джерело} - шаблон ('Замовлення дзвінка: {service}'); '=текст' - константа дослівно; інший рядок чи число -
 * константа. Порожні значення не передаються (поле в CRM лишається як є).
 */
final class Bitrix24Crm extends AbstractLeadCrm
{
    public const SYSTEM = 'bitrix24';

    /** Джерела значень для мап полів (ключ => опис для docs/site-pack.md). */
    public const SOURCES = [
        'page_url' => 'повна адреса сторінки форми (https://хост/шлях?query)',
        'site_url' => 'https://хост/ (зі слешем)',
        'host' => 'хост міста, з якого заявка',
        'city' => 'назва міста (називний, uk)',
        'service' => 'назва послуги, зі сторінки якої заявка (порожньо поза сторінкою послуги)',
        'name' => "ім'я з форми",
        'phone' => 'телефон з форми',
        'email' => 'email (у формі рушія поля нема - порожньо)',
        'message' => 'текст заявки / розрахунок калькулятора',
        'comments' => 'зведення для менеджера: сайт, послуга, сторінка, джерело, текст',
        'utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'utm_campaign' => 'utm_campaign',
        'utm_content' => 'utm_content', 'utm_term' => 'utm_term',
        'click_id' => 'gclid/fbclid', 'referrer' => 'звідки перейшли', 'landing_url' => 'сторінка входу',
        'locale' => 'мова сторінки (uk|ru)', 'lead_id' => 'номер заявки в адмінці',
    ];

    /** Дефолти app.crm.bitrix24 (пак задає лише відмінні ключі). */
    private const DEFAULTS = [
        'category_id' => null,          // воронка угоди; null = основна
        'stage_id' => '',               // стадія ('C29:NEW'); порожньо = перша стадія воронки
        'title' => 'Lead from: {host}', // TITLE угоди
        'source_id' => 'website',       // SOURCE_ID угоди
        'utm_medium_default' => '_organic', // UTM_MEDIUM, коли utm_medium порожній (як у data.php)
        'register_sonet_event' => true, // params.REGISTER_SONET_EVENT=Y: подія в стрічці, сповіщення відповідальному
        'contact_fields' => [],         // { UF_CRM_…: джерело|шаблон|константа }
        'deal_fields' => [],            // { UF_CRM_…: …, OPPORTUNITY: 0, … } - перекривають і стандартні поля угоди
    ];

    private readonly string $base;
    /** @var array<string, mixed> */
    private readonly array $config;

    /** @param array<string, mixed> $crm app.crm */
    public function __construct(
        #[Autowire(service: 'app.crm_http_client')]
        private readonly HttpClientInterface $http,
        private readonly ServiceItemRepository $items,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        #[Autowire(env: 'BITRIX24_WEBHOOK_URL')]
        string $webhookUrl = '',
        #[Autowire('%app.crm%')]
        array $crm = [],
        /** схема URL каталогу: де в шляху сторінки слаг послуги; null = flat/nested (/services/…) */
        private readonly ?CatalogUrls $catalogUrls = null,
    ) {
        parent::__construct($em, $logger);
        $this->base = self::normalizeBase($webhookUrl);
        $this->config = array_replace(self::DEFAULTS, \is_array($crm['bitrix24'] ?? null) ? $crm['bitrix24'] : []);
    }

    public function system(): string
    {
        return self::SYSTEM;
    }

    public function isEnabled(): bool
    {
        return $this->base !== '';
    }

    public function capabilities(): LeadCrmCapabilities
    {
        return new LeadCrmCapabilities(pushLead: true, dedupContact: true);
    }

    /** Наявний контакт за точним номером (crm.duplicate.findbycomm); null - нема або телефон порожній. */
    private function findContactByPhone(string $phone): ?int
    {
        if ($phone === '') {
            return null;
        }
        $found = $this->call('crm.duplicate.findbycomm', ['entity_type' => 'CONTACT', 'type' => 'PHONE', 'values' => [$phone]]);
        $ids = \is_array($found) && \is_array($found['CONTACT'] ?? null) ? $found['CONTACT'] : [];
        $id = (int) (reset($ids) ?: 0);

        return $id > 0 ? $id : null;
    }

    /** Контакт -> угода; id угоди стає crm_external_id. */
    protected function send(ServiceLead $lead): string
    {
        if (!str_starts_with($this->base, 'https://')) {
            throw new CrmException('BITRIX24_WEBHOOK_URL має бути https://<портал>/rest/<id>/<токен>/');
        }

        $src = $this->sources($lead);

        // контакт шукаємо за телефоном: інакше кожна невдала угода (невірний stage_id, UF-код) плодила б дубль контакту на кожному
        // прогоні крону
        $contactId = $this->findContactByPhone((string) ($src['phone'] ?? ''))
            ?? $this->positiveId($this->call('crm.contact.add', ['fields' => $this->contactFields($src)]), 'контакту');

        $params = (bool) $this->config['register_sonet_event'] ? ['REGISTER_SONET_EVENT' => 'Y'] : [];
        $deal = $this->dealFields($src);
        $deal['CONTACT_ID'] = $contactId;
        $dealId = $this->positiveId($this->call('crm.deal.add', array_filter(['fields' => $deal, 'params' => $params])), 'угоди');

        return (string) $dealId;
    }

    /**
     * @param array<string, string|null> $src
     *
     * @return array<string, mixed>
     */
    private function contactFields(array $src): array
    {
        $fields = [
            // ім'я у формі необов'язкове; порожнє NAME портал може відхилити - як у Pipedrive, підставляємо телефон
            'NAME' => ($src['name'] ?? '') !== '' ? $src['name'] : (string) ($src['phone'] ?? ''),
            'PHONE' => [['VALUE' => $src['phone'], 'VALUE_TYPE' => 'WORK']],
        ];
        if (($src['email'] ?? '') !== '') {
            $fields['EMAIL'] = [['VALUE' => $src['email'], 'VALUE_TYPE' => 'WORK']];
        }

        return array_replace($fields, $this->mapFields((array) $this->config['contact_fields'], $src));
    }

    /**
     * @param array<string, string|null> $src
     *
     * @return array<string, mixed>
     */
    private function dealFields(array $src): array
    {
        $fields = [
            'TITLE' => mb_substr($this->resolve((string) $this->config['title'], $src) ?? '', 0, 255),
            'SOURCE_ID' => (string) $this->config['source_id'],
            'CATEGORY_ID' => $this->config['category_id'],
            'STAGE_ID' => (string) $this->config['stage_id'],
            'COMMENTS' => $src['comments'],
            'UTM_SOURCE' => $src['utm_source'],
            'UTM_MEDIUM' => ($src['utm_medium'] ?? '') !== '' ? $src['utm_medium'] : (string) $this->config['utm_medium_default'],
            'UTM_CAMPAIGN' => $src['utm_campaign'],
            'UTM_CONTENT' => $src['utm_content'],
            'UTM_TERM' => $src['utm_term'],
        ];
        $fields = array_filter($fields, static fn (mixed $v): bool => $v !== null && $v !== '');

        return array_replace($fields, $this->mapFields((array) $this->config['deal_fields'], $src));
    }

    /**
     * @param array<mixed, mixed>        $map поле Bitrix => джерело|шаблон|константа
     * @param array<string, string|null> $src
     *
     * @return array<string, scalar>
     */
    private function mapFields(array $map, array $src): array
    {
        $out = [];
        foreach ($map as $field => $spec) {
            $value = \is_string($spec) ? $this->resolve($spec, $src) : (\is_scalar($spec) ? $spec : null);
            if ($value !== null && $value !== '') {
                $out[(string) $field] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, string|null> $src */
    private function resolve(string $spec, array $src): ?string
    {
        if (str_starts_with($spec, '=')) {
            return substr($spec, 1);
        }
        if (\array_key_exists($spec, self::SOURCES)) {
            return $src[$spec] ?? null;
        }
        if (!str_contains($spec, '{')) {
            return $spec;
        }

        $filled = false;
        $out = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($src, &$filled): string {
            if (!\array_key_exists($m[1], self::SOURCES)) {
                return $m[0];
            }
            $v = (string) ($src[$m[1]] ?? '');
            $filled = $filled || $v !== '';

            return $v;
        }, $spec);
        // жодна підстановка не дала значення («Замовлення дзвінка: {service}» без послуги) - поле не шлемо,
        // щоб у CRM не лишався обрізаний заголовок без сенсу
        if (!$filled) {
            return null;
        }

        // «Замовлення дзвінка: » без послуги - прибираємо висячий роздільник (регексом з /u:
        // rtrim з маскою з «–—» працює побайтово і відрізав би останній байт кириличної літери)
        return preg_replace('/[\s:\-–—|,]+$/u', '', (string) $out) ?? (string) $out;
    }

    /** @return array<string, string|null> */
    private function sources(ServiceLead $lead): array
    {
        $city = $lead->getCity();
        $host = $city?->getHost() ?? '';
        $page = $lead->getPageUrl();
        $pageUrl = match (true) {
            $page === null || $page === '' => null,
            str_starts_with($page, 'http://'), str_starts_with($page, 'https://') => $page,
            default => 'https://' . $host . '/' . ltrim($page, '/'),
        };
        $service = $this->serviceTitle($lead);
        $message = trim((string) $lead->getMessage());

        $comments = array_filter([
            'Заявка з сайту ' . $host,
            $service !== null ? 'Послуга: ' . $service : null,
            $pageUrl !== null ? 'Сторінка: ' . $pageUrl : null,
            'Джерело: ' . LeadAttribution::summary($lead),
            $lead->getClickId() !== null ? 'gclid: ' . $lead->getClickId() : null,
        ]);
        if ($message !== '') {
            $comments[] = '';
            $comments[] = $message;
        }

        return [
            'page_url' => $pageUrl,
            'site_url' => $host !== '' ? 'https://' . $host . '/' : null,
            'host' => $host !== '' ? $host : null,
            'city' => $city?->getNameNomUk(),
            'service' => $service,
            'name' => $lead->getName(),
            'phone' => $lead->getPhone(),
            'email' => null,
            'message' => $message !== '' ? $message : null,
            'comments' => implode("\n", $comments),
            'utm_source' => $lead->getUtmSource(),
            'utm_medium' => $lead->getUtmMedium(),
            'utm_campaign' => $lead->getUtmCampaign(),
            'utm_content' => $lead->getUtmContent(),
            'utm_term' => $lead->getUtmTerm(),
            'click_id' => $lead->getClickId(),
            'referrer' => $lead->getReferrer(),
            'landing_url' => $lead->getLandingUrl(),
            'locale' => $lead->getLocale(),
            'lead_id' => $lead->getId() !== null ? (string) $lead->getId() : null,
        ];
    }

    /**
     * Послуга - за шляхом сторінки форми під схему URL паку (CatalogUrls::itemSlugFromPath): /services/…/<slug>/ у flat
     * і nested, /<категорія>/<slug>/ у root, /<slug>/ у slug.
     */
    private function serviceTitle(ServiceLead $lead): ?string
    {
        $site = $lead->getCity()?->getSite();
        $path = (string) parse_url((string) $lead->getPageUrl(), PHP_URL_PATH);
        $slug = ($this->catalogUrls ?? new CatalogUrls())->itemSlugFromPath($path);
        if ($site === null || $slug === null) {
            return null;
        }

        $locale = $lead->getLocale() === 'ru' ? 'ru' : 'uk';
        $item = $this->items->findOneActiveBySiteAndSlug($site, $slug, $locale);
        $title = $item !== null ? trim((string) $item->loc('title', $locale)) : '';

        return $title !== '' ? $title : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function call(string $method, array $payload): mixed
    {
        try {
            $response = $this->http->request('POST', $this->base . $method . '.json', [
                'json' => $payload,
                'timeout' => 10,
                'max_duration' => 15,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new CrmException(sprintf('Bitrix24 %s: %s', $method, $this->redact($e->getMessage())));
        }

        if (isset($body['error']) || $status >= 400 || !\array_key_exists('result', $body)) {
            throw new CrmException($this->redact(sprintf(
                'Bitrix24 %s: HTTP %d %s %s',
                $method,
                $status,
                \is_scalar($body['error'] ?? null) ? (string) $body['error'] : 'без result',
                \is_scalar($body['error_description'] ?? null) ? (string) $body['error_description'] : '',
            )));
        }

        return $body['result'];
    }

    private function positiveId(mixed $result, string $what): int
    {
        $id = \is_int($result) || (\is_string($result) && ctype_digit($result)) ? (int) $result : 0;
        if ($id <= 0) {
            throw new CrmException(sprintf('Bitrix24 не повернув id %s.', $what));
        }

        return $id;
    }

    /** Адреса вебхука - секрет: прибираємо її з будь-якого тексту, що йде в лог чи адмінку. */
    private function redact(string $text): string
    {
        if ($this->base !== '') {
            $text = str_replace([$this->base, rtrim($this->base, '/')], ['<BITRIX24_WEBHOOK_URL>/', '<BITRIX24_WEBHOOK_URL>'], $text);
        }

        return (string) preg_replace('#/rest/\d+/[^/\s"\']+#', '/rest/<id>/<token>', $text);
    }

    /**
     * https://<портал>/rest/<id>/<токен>/ - зі слешем у кінці. Якщо в .env.local вставили адресу разом із методом
     * (…/crm.deal.add.json, як у старому data.php), метод відрізаємо.
     */
    private static function normalizeBase(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $url = (string) preg_replace('#(/rest/[^/]+/[^/]+)/.*$#', '$1', $url);

        return rtrim($url, '/') . '/';
    }
}
