<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Тексти послуги під місто: після відкриття піддоменів однакові сторінки послуг
 * на чотирьох хостах стали дублями, тут лежать переписані версії.
 */
final class Version20260903150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_item_city: тексти послуг під конкретне місто';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_item_city (
            id INT AUTO_INCREMENT NOT NULL,
            item_id INT NOT NULL,
            city_id INT NOT NULL,
            lead_uk LONGTEXT DEFAULT NULL,
            lead_ru LONGTEXT DEFAULT NULL,
            desc_uk LONGTEXT DEFAULT NULL,
            desc_ru LONGTEXT DEFAULT NULL,
            faq_uk JSON DEFAULT NULL,
            faq_ru JSON DEFAULT NULL,
            INDEX IDX_SIC_ITEM (item_id),
            INDEX IDX_SIC_CITY (city_id),
            UNIQUE INDEX uniq_item_city (item_id, city_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_item_city ADD CONSTRAINT FK_SIC_ITEM FOREIGN KEY (item_id) REFERENCES service_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_item_city ADD CONSTRAINT FK_SIC_CITY FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_item_city');
    }
}
