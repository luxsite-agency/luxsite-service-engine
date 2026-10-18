<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ставки калькулятора в розрізі міста. Партнерський прайс різниться: Київ
 * дорожчий за решту рівно на 10%, і одна ціна на чотири міста означала б
 * суму, якої менеджер не підтвердить.
 *
 * Порожній рядок або порожнє поле - не помилка: діє базова ставка типу.
 */
final class Version20260913220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_calc_type_city: ціни калькулятора по містах';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_calc_type_city (
            id INT AUTO_INCREMENT NOT NULL,
            type_id INT NOT NULL,
            city_id INT NOT NULL,
            price_per_sqm NUMERIC(10, 2) DEFAULT NULL,
            min_price NUMERIC(10, 2) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_calc_type_city_type (type_id),
            INDEX IDX_calc_type_city_city (city_id),
            UNIQUE INDEX uniq_calc_type_city (type_id, city_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE service_calc_type_city
            ADD CONSTRAINT FK_calc_type_city_type FOREIGN KEY (type_id)
                REFERENCES service_calc_type (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_calc_type_city_city FOREIGN KEY (city_id)
                REFERENCES service_city (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_calc_type_city');
    }
}
