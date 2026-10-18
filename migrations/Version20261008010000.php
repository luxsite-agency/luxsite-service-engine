<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * service_city: адреса офісу (uk/ru) для футера й streetAddress у розмітці.
 * Колонки порожні, поки власник сайту не заповнить адресу в адмінці (Міста).
 *
 * Злиття рушія (10.2026): на БД, де колонки додала Version20261008100000, крок пропускається.
 * Первісна версія ще й записувала адресу апекс-міста першого сайту; у відкритому рушії цих даних нема
 * (на його проді міграція вже виконана), схема та сама.
 */
final class Version20261008010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_city: address_uk, address_ru';
    }

    public function up(Schema $schema): void
    {
        // Без skipIf: SkipMigration не записує версію в doctrine_migration_versions, і крок повторювався б на
        // кожному деплої (doctor: «є невиконані»). Порожнє up() записується як виконане (Doctrine лише попередить про 0 SQL).
        if ($schema->getTable('service_city')->hasColumn('address_uk')) {
            $this->write('пропуск: ' . 'service_city.address_* уже є (Version20261008100000)');
        
            return;
        }

        $this->addSql('ALTER TABLE service_city
            ADD address_uk VARCHAR(255) DEFAULT NULL,
            ADD address_ru VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city
            DROP address_uk,
            DROP address_ru');
    }
}
