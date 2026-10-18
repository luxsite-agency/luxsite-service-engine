<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Pipedrive;

use App\Entity\ServiceLead;
use App\ServiceSite\Crm\AbstractLeadCrm;
use App\ServiceSite\Crm\LeadCrmCapabilities;
use App\ServiceSite\LeadAttribution;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Конектор Pipedrive (app.crm.system: pipedrive, або auto + PIPEDRIVE_API_TOKEN): заявка з сайту ->
 * контакт + лід у Pipedrive клієнта/партнера. Логіка - колишній PipedriveLeadSync без змін.
 *
 * Саме лід, а не угода: так просив партнер першого сайту з Pipedrive. Лід лежить у Leads Inbox, менеджер
 * конвертує його в угоду вже після дзвінка - воронку й стадію ми не займаємо.
 *
 * Усе, що належить конкретному акаунту Pipedrive (хеші кастомних полів, id опцій
 * «Джерело», «Місто», «Тип угоди», префікс назви ліда), - у параметрі app.pipedrive
 * з паку сайту (config/site/parameters.local.yaml); у рушії дефолт порожній, тоді
 * передаються лише контакт, лід і примітка. Мітку (Гарячий/Теплий/Холодний) не
 * ставимо: це оцінка менеджера після розмови.
 */
final class PipedriveCrm extends AbstractLeadCrm
{
    public const SYSTEM = 'pipedrive';

    /** «Опис об'єкта» - varchar, довгий розрахунок калькулятора туди не влізе. */
    private const OBJECT_MAX = 255;

    /** @var array<string, string> ключ поля (city, deal_type, source, utm_source, object) => хеш кастомного поля */
    private readonly array $fields;
    private readonly ?int $sourceOption;
    /** @var array<string, int> хост міста => id опції «Місто» */
    private readonly array $cityOptions;
    /** @var array<string, int> слаг послуги => id опції «Тип угоди» (лише однозначні збіги) */
    private readonly array $typeBySlug;
    private readonly string $titlePrefix;

    /** @param array<string, mixed> $config app.pipedrive */
    public function __construct(
        private readonly PipedriveClient $client,
        EntityManagerInterface $em,
        LoggerInterface $logger,
        #[Autowire(env: 'int:PIPEDRIVE_OWNER_ID')]
        private readonly int $ownerId = 0,
        #[Autowire('%app.pipedrive%')]
        array $config = [],
    ) {
        parent::__construct($em, $logger);
        $this->fields = array_filter((array) ($config['fields'] ?? []), static fn ($v): bool => \is_string($v) && $v !== '');
        $this->sourceOption = isset($config['source_option']) && $config['source_option'] !== null ? (int) $config['source_option'] : null;
        $this->cityOptions = array_map('intval', (array) ($config['city_options'] ?? []));
        $this->typeBySlug = array_map('intval', (array) ($config['type_by_slug'] ?? []));
        $this->titlePrefix = (string) ($config['title_prefix'] ?? '');
    }

    public function system(): string
    {
        return self::SYSTEM;
    }

    public function isEnabled(): bool
    {
        return $this->client->isEnabled();
    }

    public function capabilities(): LeadCrmCapabilities
    {
        return new LeadCrmCapabilities(pushLead: true, dedupContact: true);
    }

    /** Контакт (без дубля за номером) -> лід -> примітка; id ліда (uuid) стає crm_external_id. */
    protected function send(ServiceLead $lead): string
    {
        $personId = $this->findOrCreatePerson($lead);
        $leadId = $this->createLead($lead, $personId);
        try {
            $this->addNote($lead, $leadId);
        } catch (\Throwable $e) {
            // лід уже створено: виняток тут змусив би крон створити другий лід; примітка - не критична
            $this->logger->warning('Pipedrive: лід створено, примітка не додалась: ' . $e->getMessage(), ['lead_id' => $lead->getId()]);
        }

        return $leadId;
    }

    /** Дубль контакту не створюємо: спершу шукаємо за точним номером. */
    private function findOrCreatePerson(ServiceLead $lead): int
    {
        $phone = $lead->getPhone();
        $found = $this->client->get('persons/search', [
            'term' => $phone,
            'fields' => 'phone',
            'exact_match' => 'true',
            'limit' => 1,
        ]);

        $id = $found['items'][0]['item']['id'] ?? null;
        if (\is_int($id)) {
            return $id;
        }

        $person = $this->client->post('persons', array_filter([
            'name' => $lead->getName() ?? $phone,
            'phone' => [['value' => $phone, 'primary' => true, 'label' => 'mobile']],
            'owner_id' => $this->ownerId ?: null,
        ]));

        $id = $person['id'] ?? null;
        if (!\is_int($id)) {
            throw new PipedriveException('Pipedrive не повернув id контакту.');
        }

        return $id;
    }

    private function createLead(ServiceLead $lead, int $personId): string
    {
        $city = $lead->getCity();
        $host = $city?->getHost() ?? '';

        $payload = [
            'title' => $this->title($lead),
            'person_id' => $personId,
            'owner_id' => $this->ownerId ?: null,
        ];
        // кастомні поля - лише ті, хеші яких задав пак
        $custom = [
            'source' => $this->sourceOption,
            'city' => $this->cityOptions[$host] ?? null,
            'deal_type' => $this->dealType($lead),
            'utm_source' => $lead->getUtmSource(),
            'object' => $this->objectSummary($lead),
        ];
        foreach ($custom as $key => $value) {
            if (isset($this->fields[$key])) {
                $payload[$this->fields[$key]] = $value;
            }
        }
        $payload = array_filter($payload, static fn (mixed $v): bool => $v !== null && $v !== '');

        $created = $this->client->post('leads', $payload);
        $id = $created['id'] ?? null;
        if (!\is_string($id)) {
            throw new PipedriveException('Pipedrive не повернув id ліда.');
        }

        return $id;
    }

    /** Назва в Leads Inbox: за нею менеджер одразу бачить, що це наш і звідки (префікс - бренд із паку або назва сайту). */
    private function title(ServiceLead $lead): string
    {
        $city = $lead->getCity()?->getNameNomUk() ?? '';
        $prefix = $this->titlePrefix !== '' ? $this->titlePrefix : ($lead->getCity()?->getSite()?->getName() ?? '');
        $parts = array_filter([$prefix, $city, $lead->getPhone()]);

        return mb_substr(implode(' - ', $parts), 0, 255);
    }

    /** Тип послуги беремо зі слага сторінки, з якої залишили заявку. */
    private function dealType(ServiceLead $lead): ?int
    {
        $path = (string) parse_url((string) $lead->getPageUrl(), PHP_URL_PATH);
        foreach ($this->typeBySlug as $slug => $option) {
            if (str_contains($path, '/' . $slug)) {
                return $option;
            }
        }

        return null;
    }

    /** Коротка суть у картці; повний текст іде в примітку. */
    private function objectSummary(ServiceLead $lead): ?string
    {
        $message = trim((string) $lead->getMessage());
        if ($message === '') {
            return null;
        }

        $flat = trim((string) preg_replace('/\s+/u', ' ', $message));

        return mb_substr($flat, 0, self::OBJECT_MAX);
    }

    /**
     * Примітка тримає те, що не влазить у поля: повний текст заявки, звідки
     * людина прийшла і яку сторінку читала перед тим.
     */
    private function addNote(ServiceLead $lead, string $leadId): void
    {
        $host = $lead->getCity()?->getHost() ?? '';
        $rows = [
            'Заявка з сайту ' . $host,
            'Телефон: ' . $lead->getPhone(),
            "Ім'я: " . ($lead->getName() ?? '-'),
            'Джерело: ' . LeadAttribution::summary($lead),
        ];

        if ($lead->getClickId() !== null) {
            $rows[] = 'gclid: ' . $lead->getClickId();
        }
        if ($lead->getPageUrl() !== null) {
            $rows[] = 'Сторінка: https://' . $host . $lead->getPageUrl();
        }
        if (($message = trim((string) $lead->getMessage())) !== '') {
            $rows[] = '';
            $rows[] = $message;
        }

        // Pipedrive рендерить примітку як HTML, тож переноси робимо тегами
        $this->client->post('notes', [
            'lead_id' => $leadId,
            'content' => implode('<br>', array_map(htmlspecialchars(...), $rows)),
        ]);
    }
}
