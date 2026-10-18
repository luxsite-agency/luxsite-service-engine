<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * «Про нас»: абзац «Де ми працюємо» - свій для кожного міста (service_city.about_where_*).
 *
 * Спільний текст сайту пишуть під апекс-місто, і на хостах інших міст стояли його райони й передмістя
 * (CityWordsSubscriber міняє лише назву міста). Розділ виноситься зі спільного тексту: у «Про нас» на його місці
 * маркер <!--city-where-->, а сам абзац лежить у service_city.about_where_* і правиться в адмінці (Міста).
 * Райони - з service_district, передмістя - з відповіді FAQ «Де ви працюєте» цього ж міста, щоб сторінки не суперечили.
 *
 * Первісна версія ще й переносила тексти міст першого сайту та вирізала розділ з його «Про нас»; у відкритому рушії
 * цих даних нема (на його проді міграція вже виконана), лишилась лише схема.
 */
final class Version20261008020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_city.about_where_*; розділ «Де ми працюємо» зі спільного «Про нас» - у міста';
    }

    public function up(Schema $schema): void
    {
        // Без skipIf: SkipMigration не записує версію в doctrine_migration_versions, і крок повторювався б на
        // кожному деплої (doctor: «є невиконані»). Порожнє up() записується як виконане (Doctrine лише попередить про 0 SQL).
        if ($schema->getTable('service_city')->hasColumn('about_where_uk')) {
            $this->write('пропуск: ' . 'service_city.about_where_* уже є');
        
            return;
        }

        $this->addSql('ALTER TABLE service_city
            ADD about_where_uk LONGTEXT DEFAULT NULL,
            ADD about_where_ru LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city
            DROP about_where_uk,
            DROP about_where_ru');
    }
}
