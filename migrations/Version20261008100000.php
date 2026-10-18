<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * service_city: адреса офісу address_uk/address_ru (гілка другого сайту).
 *
 * Злиття рушія (10.2026): версія з гілки другого сайту, уже виконана на його БД. У рушії лишена,
 * щоб ланцюг міграцій покривав обидві прод-БД; на БД першого сайту (де те саме зробила
 * Version20261008010000) крок пропускається - skipIf позначає версію виконаною.
 */
final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_city: address_uk, address_ru - гілка другого сайту';
    }

    public function up(Schema $schema): void
    {
        // Без skipIf: SkipMigration не записує версію в doctrine_migration_versions, і крок повторювався б на
        // кожному деплої (doctor: «є невиконані»). Порожнє up() записується як виконане (Doctrine лише попередить про 0 SQL).
        if ($schema->getTable('service_city')->hasColumn('address_uk')) {
            $this->write('пропуск: ' . 'service_city.address_uk уже є (Version20261008010000)');
        
            return;
        }

        $this->addSql('ALTER TABLE service_city ADD address_uk VARCHAR(255) DEFAULT NULL, ADD address_ru VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // колонки спільні з Version20261008010000 - відкат роблять лише разом (вручну)
    }
}
