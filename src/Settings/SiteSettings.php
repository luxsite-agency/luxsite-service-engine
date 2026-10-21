<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Settings;

use App\Entity\ServiceSite;
use App\Entity\ServiceSiteSetting;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Читання налаштувань сайту (SiteSettingKeys) — один індексований запит на сайт за запит, без постійного кешу: зміна
 * в адмінці, відновлення БД з бекапу чи правка SQL діють одразу. Секрети (TYPE_SECRET): у БД конверт SecretBox, get()
 * віддає лише маску — відкритий текст тільки через secret(), і лише тому, хто шле його провайдеру (Google OAuth).
 * Конверт, що не відкривається чинним SETTINGS_SECRET_KEY, — не 500: secretState() = Unreadable, secret() = null,
 * один запис settings.secret_unreadable у security_audit на процес.
 */
final class SiteSettings implements ResetInterface
{
    /** @var array<int, array<string, ServiceSiteSetting>> id сайту → код → рядок */
    private array $loaded = [];
    /** @var array<string, array{raw: string|null, state: SecretState, plain: string|null}> «id:код» → розшифроване */
    private array $secrets = [];
    /** @var array<string, true> */
    private array $unreadableReported = [];

    private bool $tableMissingLogged = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SecretBox $box,
        #[Autowire(service: 'monolog.logger.security_audit')]
        private readonly LoggerInterface $audit,
    ) {
    }

    /** Значення ключа; для секрету — маска («…a1b2») або null: відкритий текст звідси не витече в шаблон чи лог. */
    public function get(ServiceSite $site, string $code): ?string
    {
        SiteSettingKeys::assertKnown($code);
        $raw = $this->raw($site, $code);

        return SiteSettingKeys::isSecret($code) ? SecretBox::mask($raw) : $raw;
    }

    /** Значення у форматі ключа або null (порожньо / невалідне). */
    public function valid(ServiceSite $site, string $code): ?string
    {
        return SiteSettingKeys::validValue($code, $this->get($site, $code));
    }

    public function getBool(ServiceSite $site, string $code): bool
    {
        return \in_array(mb_strtolower(trim((string) $this->get($site, $code))), ['1', 'true', 'yes', 'on'], true);
    }

    /** Відкритий секрет; null — не задано, нема ключа шифрування або конверт не розшифрувати (secretState()). */
    public function secret(ServiceSite $site, string $code): ?string
    {
        return $this->resolveSecret($site, $code)['plain'];
    }

    public function secretState(ServiceSite $site, string $code): SecretState
    {
        return $this->resolveSecret($site, $code)['state'];
    }

    /** Для CLI й тестів: секрет — відкритим текстом (шифрується тут), решта — як є (нормалізація id — у сутності). */
    public function set(ServiceSite $site, string $code, #[SensitiveParameter] ?string $value): void
    {
        SiteSettingKeys::assertKnown($code);
        $row = $this->row($site, $code) ?? new ServiceSiteSetting($site, $code);
        if (SiteSettingKeys::isSecret($code)) {
            $value = null === $value || '' === trim($value) ? null : $this->box->encrypt(trim($value));
        }
        $row->setValue($value);
        $this->em->persist($row);
        $this->em->flush();
        $this->invalidate();
    }

    /** Рядки для всіх відомих ключів сайту, щоб список в адмінці був повним (значення null = не задано). */
    public function ensureRows(ServiceSite $site): void
    {
        $existing = array_keys($this->rows($site));
        $missing = array_diff(SiteSettingKeys::all(), $existing);
        if ([] === $missing) {
            return;
        }
        foreach ($missing as $code) {
            $this->em->persist(new ServiceSiteSetting($site, $code));
        }
        $this->em->flush();
        $this->invalidate();
    }

    /** Чи задано хоч одне налаштування (для дашборду й тестів «сайт без налаштувань = як раніше»). */
    public function hasAny(ServiceSite $site): bool
    {
        foreach ($this->rows($site) as $row) {
            if (null !== $row->getValue() && '' !== trim($row->getValue())) {
                return true;
            }
        }

        return false;
    }

    public function invalidate(): void
    {
        $this->loaded = [];
        $this->secrets = [];
    }

    public function reset(): void
    {
        $this->invalidate();
    }

    private function raw(ServiceSite $site, string $code): ?string
    {
        return $this->row($site, $code)?->getValue();
    }

    private function row(ServiceSite $site, string $code): ?ServiceSiteSetting
    {
        return $this->rows($site)[$code] ?? null;
    }

    /** @return array<string, ServiceSiteSetting> */
    private function rows(ServiceSite $site): array
    {
        $id = $site->getId();
        if (null === $id) {
            return [];
        }
        if (!isset($this->loaded[$id])) {
            $this->loaded[$id] = [];
            try {
                $rows = $this->em->getRepository(ServiceSiteSetting::class)->findBy(['site' => $site]);
            } catch (TableNotFoundException) {
                // реліз уже віддає сторінки, а міграція ще не пройшла (docker-деплой: compose up раніше за migrate) -
                // сайт поводиться «як без налаштувань», а не 500 на кожній сторінці
                if (!$this->tableMissingLogged) {
                    $this->tableMissingLogged = true;
                    $this->audit->warning('settings.table_missing: таблиці service_site_setting ще нема (міграції не виконано)');
                }
                $rows = [];
            }
            foreach ($rows as $row) {
                $this->loaded[$id][$row->getCode()] = $row;
            }
        }

        return $this->loaded[$id];
    }

    /** @return array{raw: string|null, state: SecretState, plain: string|null} */
    private function resolveSecret(ServiceSite $site, string $code): array
    {
        SiteSettingKeys::assertKnown($code);
        if (!SiteSettingKeys::isSecret($code)) {
            throw new \InvalidArgumentException(\sprintf('Ключ «%s» не секретний', $code));
        }
        $raw = $this->raw($site, $code);
        $key = (int) $site->getId() . ':' . $code;
        if (isset($this->secrets[$key]) && $this->secrets[$key]['raw'] === $raw) {
            return $this->secrets[$key];
        }
        $plain = null;
        if (null === $raw || '' === trim($raw)) {
            $state = SecretState::Missing;
        } elseif (!$this->box->isAvailable()) {
            $state = SecretState::NoKey;
        } else {
            try {
                $plain = $this->box->decrypt($raw);
                $state = SecretState::Ready;
            } catch (SecretUnreadableException $e) {
                $state = SecretState::Unreadable;
                if (!isset($this->unreadableReported[$key])) {
                    $this->unreadableReported[$key] = true;
                    $this->audit->warning('settings.secret_unreadable', [
                        'event' => 'settings.secret_unreadable',
                        'site_id' => $site->getId(),
                        'code' => $code,
                        'mask' => SecretBox::mask($raw),
                        'reason' => $e->getMessage(),
                        'key_problem' => $this->box->keyProblem(),
                    ]);
                }
            }
        }

        return $this->secrets[$key] = ['raw' => $raw, 'state' => $state, 'plain' => $plain];
    }
}
