<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite\Redirect;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Правила редиректів (перенесено з shop-engine E3, адаптовано під мультитенант по Host): пошук на 404, ручні правила
 * (адмінка, імпорт CSV, API), авто-301 при зміні slug, перенесення з паку.
 *
 * Область правила — сайт + місто (city_key = id міста) або сайт цілком (city_key = 0). На 404 хоста міста береться
 * правило міста, інакше сайту — один запит за унікальним індексом. Шлях шукається і в альтернативній формі слеша
 * (/old і /old/): гігієна URL паку (trailing_slash) дописує слеш ще до роутингу, тож правило, заведене без слеша, має
 * спрацьовувати так само.
 *
 * Інваріант: ланцюгів у межах області нема — ціль 301/302 (шлях без query) ніколи не є джерелом іншого 301/302 тієї
 * самої області. Тримається злиттям: нове правило A→B бере кінцеву ціль B (B→C ⇒ A→C), а правила X→A переписуються на
 * ту саму ціль (індекс target_path). Правило сайту переписує й правила міст, у яких для A нема власного правила;
 * правило міста — лише правила свого міста (правила сайту на інших хостах лишаються чинними). Цикл (A→B при B→…→A) і
 * редирект на себе — відмова для ручних правил; правило сайту діє на кожному хості поверх правил міста, тому цикл
 * шукається і через правила кожного міста (місто B→A + сайт A→B — цикл на хості B). Авто-301 циклів не дає, бо нові
 * адреси живі й правила з таким джерелом видаляються. Абсолютна ціль на хості будь-якого міста сайту — це шлях (інакше
 * A→https://site/B і B→A обходили б перевірку циклу). Ручні правила пишуться під блокуванням (locked()): дві паралельні
 * форми A→B і B→A не дадуть циклу.
 */
final class RedirectManager
{
    private const int MAX_CHAIN = 20;
    private const int CHUNK = 500;
    public const string EXTERNAL_DENIED = 'Ціль на іншому сайті може задати лише адміністратор; менеджеру — шлях від кореня (/…)';
    public const string TARGET_REQUIRED = 'Для 301/302 вкажіть ціль: шлях від кореня (/…) або повний URL https://…';

    private const string LOCK = 'seo-redirect-rules';

    public function __construct(
        private readonly Connection $db,
        private readonly LiveUrls $live,
        #[Autowire(service: 'app.seo_lock_factory')] private readonly LockFactory $locks,
    ) {
    }

    /** Абсолютна ціль на хості міста сайту (чи апексі) → шлях. */
    public function localize(ServiceSite $site, string $target): string
    {
        if (!RedirectPath::isAbsoluteUrl($target)) {
            return $target;
        }
        $hosts = $this->db->fetchFirstColumn('SELECT host FROM service_city WHERE site_id = ?', [$site->getId()]);
        $hosts[] = $site->getApexDomain();

        return RedirectPath::localize($target, ...array_map('strval', $hosts));
    }

    /**
     * Перевірка й запис ручного правила під одним блокуванням (паралельні A→B і B→A).
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    public function locked(callable $write): mixed
    {
        $lock = $this->locks->createLock(self::LOCK, 30);
        $lock->acquire(true);
        try {
            return $write();
        } finally {
            $lock->release();
        }
    }

    /**
     * Правило для шляху на хості міста: спершу правило міста, потім сайту; точний збіг слеша має перевагу над
     * альтернативною формою.
     *
     * @return array{id: int, code: RedirectCode, target: string|null}|null
     */
    public function find(int $siteId, int $cityId, string $path): ?array
    {
        $alt = $path !== '/' ? (str_ends_with($path, '/') ? rtrim($path, '/') : $path . '/') : $path;
        $row = $this->db->fetchAssociative(
            'SELECT id, code, target FROM service_redirect WHERE site_id = ? AND city_key IN (?, 0) AND source IN (?, ?)
             ORDER BY (source = ?) DESC, city_key DESC LIMIT 1',
            [$siteId, $cityId, $path, $alt, $path],
        );
        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'code' => RedirectCode::from((int) $row['code']), 'target' => $row['target'] === null ? null : (string) $row['target']];
    }

    public function hit(int $id): void
    {
        $this->db->executeStatement('UPDATE service_redirect SET hits = hits + 1, last_hit_at = NOW() WHERE id = ?', [$id]);
    }

    /**
     * Повідомлення, чому ручне правило не можна зберегти, або null. Ціль — уже нормалізована й локалізована. Жива адреса
     * — відмова: правило не спрацювало б, а злиття ланцюгів переписало б на його ціль правила, що ведуть на цю сторінку.
     */
    public function check(ServiceSite $site, ?ServiceCity $city, string $source, RedirectCode $code, ?string $target): ?string
    {
        if (($live = $this->liveMessage($site, $city, $source)) !== null) {
            return $live;
        }
        if (!$code->isRedirect()) {
            return null;
        }
        if ($target === null) {
            return self::TARGET_REQUIRED;
        }
        try {
            $this->resolve($site, $city, $source, $target);
        } catch (InvalidRedirect $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** Повідомлення, чому для $source не можна завести правило жодного коду (адреса жива), або null. */
    public function liveMessage(ServiceSite $site, ?ServiceCity $city, string $source): ?string
    {
        if (!$this->live->isLive($site, $city, $source)) {
            return null;
        }

        return sprintf('Адреса %s зараз відкривається на сайті%s — правило спрацьовує лише на 404. Спершу зніміть сторінку з публікації або змініть її адресу (тоді 301 запишеться сам).', $source, $city === null ? ' (хоча б в одному місті)' : '');
    }

    /**
     * Кінцева ціль правила $source → $target в області (місто → сайт): якщо ціль — джерело іншого 301/302, береться його
     * ціль (і так далі). Для правила сайту кінцева ціль — з ланцюга правил сайту (вона має бути чинною на всіх хостах),
     * а цикл перевіряється ще й на кожному хості, де є правила міста й нема власного правила для $source: там правило
     * сайту діє поверх правил міста (місто B→A + сайт A→B = цикл на хості B).
     *
     * @throws InvalidRedirect редирект на себе або цикл
     */
    public function resolve(ServiceSite $site, ?ServiceCity $city, string $source, string $target): string
    {
        $siteId = (int) $site->getId();
        $target = $this->localize($site, $target);
        $final = $this->walk($siteId, self::key($city), $source, $target);
        if ($city !== null) {
            return $final;
        }
        $cityKeys = $this->db->fetchFirstColumn(
            'SELECT DISTINCT city_key FROM service_redirect WHERE site_id = ? AND city_key <> 0
             AND city_key NOT IN (SELECT city_key FROM service_redirect WHERE site_id = ? AND city_key <> 0 AND source = ?)',
            [$siteId, $siteId, $source],
        );
        foreach ($cityKeys as $cityKey) {
            try {
                $this->walk($siteId, (int) $cityKey, $source, $target);
            } catch (InvalidRedirect $e) {
                $host = (string) $this->db->fetchOne('SELECT host FROM service_city WHERE id = ?', [(int) $cityKey]);
                throw new InvalidRedirect(sprintf('%s (через правило міста %s)', $e->getMessage(), $host !== '' ? $host : '#' . $cityKey), 0, $e);
            }
        }

        return $final;
    }

    /**
     * Ланцюг від $target в області (правило міста $cityKey → правило сайту) до кінцевої цілі.
     *
     * @throws InvalidRedirect редирект на себе або цикл
     */
    private function walk(int $siteId, int $cityKey, string $source, string $target): string
    {
        $seen = [$source => true];
        $current = $target;
        for ($i = 0; $i < self::MAX_CHAIN; ++$i) {
            $path = RedirectPath::targetPath($current);
            if ($path === null) {
                return $current;
            }
            if (isset($seen[$path])) {
                throw new InvalidRedirect($path === $source && $i === 0 ? 'Редирект на ту саму адресу' : sprintf('Цикл редиректів: %s → %s веде назад на %s', $source, $target, $path));
            }
            $seen[$path] = true;
            $row = $this->db->fetchAssociative(
                'SELECT code, target FROM service_redirect WHERE site_id = ? AND city_key IN (?, 0) AND source = ? ORDER BY city_key DESC LIMIT 1',
                [$siteId, $cityKey, $path],
            );
            if ($row === false || $row['target'] === null || !RedirectCode::from((int) $row['code'])->isRedirect()) {
                return $current;
            }
            $current = (string) $row['target'];
        }

        throw new InvalidRedirect(sprintf('Задовгий ланцюг редиректів від %s', $source));
    }

    /**
     * Після збереження правила: його джерело знято з журналу 404 (міста або всіх міст сайту), правила X→source ведуть
     * одразу на кінцеву ціль. Ціль — уже після resolve().
     */
    public function merge(ServiceSite $site, ?ServiceCity $city, string $source, RedirectCode $code, ?string $target): void
    {
        $alt = $source !== '/' ? (str_ends_with($source, '/') ? rtrim($source, '/') : $source . '/') : $source;
        if ($city === null) {
            $this->db->executeStatement('DELETE FROM service_not_found WHERE site_id = ? AND path IN (?, ?)', [$site->getId(), $source, $alt]);
        } else {
            $this->db->executeStatement('DELETE FROM service_not_found WHERE site_id = ? AND city_id = ? AND path IN (?, ?)', [$site->getId(), $city->getId(), $source, $alt]);
        }
        if ($code->isRedirect() && $target !== null) {
            $this->repoint((int) $site->getId(), self::key($city), $source, $target);
        }
    }

    /**
     * Створити або замінити правило за джерелом в області (імпорт CSV, API, пак). Повертає true, якщо правило нове.
     * $allowExternal = false — ціль лише на своєму сайті (менеджер без ROLE_ADMIN не веде відвідувачів на чужі домени).
     *
     * @throws InvalidRedirect
     */
    public function save(ServiceSite $site, ?ServiceCity $city, string $rawSource, RedirectCode $code, ?string $rawTarget, RedirectKind $kind = RedirectKind::Manual, bool $allowExternal = true): bool
    {
        $source = RedirectPath::normalize($rawSource) ?? throw new InvalidRedirect(sprintf('Невалідна адреса-джерело «%s»: потрібен шлях від кореня сайту', $rawSource));
        $target = null;
        if ($code->isRedirect() && $rawTarget !== null && trim($rawTarget) !== '') {
            $target = RedirectPath::normalizeTarget($rawTarget) ?? throw new InvalidRedirect(sprintf('Невалідна ціль «%s»: шлях від кореня (/…) або повний URL https://…', $rawTarget));
            $target = $this->localize($site, $target);
            if (!$allowExternal && RedirectPath::isAbsoluteUrl($target)) {
                throw new InvalidRedirect(self::EXTERNAL_DENIED);
            }
        }

        return $this->locked(function () use ($site, $city, $source, $code, $target, $kind): bool {
            if (($error = $this->check($site, $city, $source, $code, $target)) !== null) {
                throw new InvalidRedirect($error);
            }
            $target = $target === null ? null : $this->resolve($site, $city, $source, $target);

            return $this->db->transactional(function () use ($site, $city, $source, $code, $target, $kind): bool {
                $created = $this->upsert((int) $site->getId(), self::key($city), $source, $code, $target, $kind) === 1;
                $this->merge($site, $city, $source, $code, $target);

                return $created;
            });
        });
    }

    /**
     * Авто-301: адреси сутностей змінились (slug послуги, категорії, поста — область сайту; району — область міста).
     * Нові адреси живі — правила з таким джерелом видаляються (і старе A→B, коли B повернули назад на A); стара → нова
     * (301, auto), правила, що вели на стару, — на нову. Обмін адресами (A↔B в одному пакеті) правил не дає: обидві живі.
     *
     * @param iterable<array{0: string, 1: string}> $moves пари [стара, нова] шляхів сайту
     *
     * @return int записаних правил
     */
    public function recordMoves(ServiceSite $site, ?ServiceCity $city, iterable $moves): int
    {
        $pairs = [];
        foreach ($moves as [$old, $new]) {
            $old = RedirectPath::normalize($old);
            $new = RedirectPath::normalize($new);
            if ($old !== null && $new !== null && $old !== $new) {
                $pairs[$old] = $new;
            }
        }
        if ($pairs === []) {
            return 0;
        }
        $live = array_flip(array_values($pairs));

        return $this->db->transactional(function () use ($site, $city, $pairs, $live): int {
            $this->release($site, $city, array_keys($live));
            $written = 0;
            foreach ($pairs as $old => $new) {
                if (isset($live[$old])) {
                    continue;
                }
                $this->upsert((int) $site->getId(), self::key($city), $old, RedirectCode::Permanent, $new, RedirectKind::Auto);
                $this->repoint((int) $site->getId(), self::key($city), $old, $new);
                ++$written;
            }

            return $written;
        });
    }

    /**
     * Адреси щойно стали живими (нова сутність, новий slug): правила з таким джерелом більше не потрібні — в області
     * сайту (city null: усі міста) чи лише міста; правила, що вели на них, лишаються — сторінка знову є.
     *
     * @param list<string> $paths нормалізовані шляхи
     */
    public function release(ServiceSite $site, ?ServiceCity $city, array $paths): void
    {
        foreach (array_chunk(array_values(array_unique($paths)), self::CHUNK) as $chunk) {
            if ($city === null) {
                $this->db->executeStatement('DELETE FROM service_redirect WHERE site_id = ? AND source IN (?)', [$site->getId(), $chunk], [ParameterType::INTEGER, ArrayParameterType::STRING]);
            } else {
                $this->db->executeStatement('DELETE FROM service_redirect WHERE site_id = ? AND city_key = ? AND source IN (?)', [$site->getId(), $city->getId(), $chunk], [ParameterType::INTEGER, ParameterType::INTEGER, ArrayParameterType::STRING]);
            }
        }
    }

    /** Видалити правило за id (адмінка, API). */
    public function delete(int $id): bool
    {
        return $this->db->executeStatement('DELETE FROM service_redirect WHERE id = ?', [$id]) > 0;
    }

    public static function key(?ServiceCity $city): int
    {
        return (int) ($city?->getId() ?? 0);
    }

    /**
     * X→$from ⇒ X→$to; правило, що стало б «на себе» ($to→$from), видаляється: в області міста — лише його, для правила
     * сайту — в усіх областях (воно діє на кожному хості; ручні правила сюди не доходять — resolve() дав би цикл).
     */
    private function repoint(int $siteId, int $cityKey, string $from, string $to): void
    {
        $toPath = RedirectPath::targetPath($to);
        if ($toPath !== null && $cityKey !== 0) {
            $this->db->executeStatement('DELETE FROM service_redirect WHERE site_id = ? AND city_key = ? AND target_path = ? AND source = ?', [$siteId, $cityKey, $from, $toPath]);
        } elseif ($toPath !== null) {
            $this->db->executeStatement('DELETE FROM service_redirect WHERE site_id = ? AND target_path = ? AND source = ?', [$siteId, $from, $toPath]);
        }
        if ($cityKey !== 0) {
            $this->db->executeStatement('UPDATE service_redirect SET target = ?, target_path = ? WHERE site_id = ? AND city_key = ? AND target_path = ? AND source <> ?', [$to, $toPath, $siteId, $cityKey, $from, $from]);

            return;
        }
        // правило сайту: переписуємо правила сайту й тих міст, де для $from нема власного правила
        $cityRules = array_map('intval', $this->db->fetchFirstColumn('SELECT city_key FROM service_redirect WHERE site_id = ? AND city_key <> 0 AND source = ?', [$siteId, $from]));
        $ids = $this->db->fetchFirstColumn('SELECT id FROM service_redirect WHERE site_id = ? AND target_path = ? AND source <> ? AND city_key NOT IN (?)', [$siteId, $from, $from, $cityRules === [] ? [-1] : $cityRules], [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ArrayParameterType::INTEGER]);
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->db->executeStatement('UPDATE service_redirect SET target = ?, target_path = ? WHERE id IN (?)', [$to, $toPath, $chunk], [ParameterType::STRING, ParameterType::STRING, ArrayParameterType::INTEGER]);
        }
    }

    /** @return int 1 — вставлено, 2 — оновлено, 0 — без змін (affected rows MySQL) */
    private function upsert(int $siteId, int $cityKey, string $source, RedirectCode $code, ?string $target, RedirectKind $kind): int
    {
        return (int) $this->db->executeStatement(
            'INSERT INTO service_redirect (site_id, city_id, city_key, source, target, target_path, code, kind, hits, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW()) AS new
             ON DUPLICATE KEY UPDATE target = new.target, target_path = new.target_path, code = new.code, kind = new.kind',
            [$siteId, $cityKey === 0 ? null : $cityKey, $cityKey, $source, $target, $target === null ? null : RedirectPath::targetPath($target), $code->value, $kind->value],
        );
    }
}
