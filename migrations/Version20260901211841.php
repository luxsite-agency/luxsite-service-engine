<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Калькулятор вартості клінінгу: типи прибирання (ціна за м² + мін. чек),
 * коефіцієнти по групах (обʼєкт, стан, терміновість, поверх, вихідний)
 * і додаткові опції (фіксована ціна або ціна за одиницю).
 */
final class Version20260901211841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Таблиці калькулятора: service_calc_type, service_calc_factor, service_calc_option';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_calc_factor (id INT AUTO_INCREMENT NOT NULL, group_code VARCHAR(32) NOT NULL, name_uk VARCHAR(128) NOT NULL, name_ru VARCHAR(128) DEFAULT NULL, multiplier NUMERIC(5, 2) DEFAULT \'1.00\' NOT NULL, is_default TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_3D5A0922F6BD1646 (site_id), INDEX idx_svc_calc_factor_site (site_id, group_code, active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_calc_option (id INT AUTO_INCREMENT NOT NULL, name_uk VARCHAR(128) NOT NULL, name_ru VARCHAR(128) DEFAULT NULL, price NUMERIC(10, 2) NOT NULL, per_unit TINYINT DEFAULT 0 NOT NULL, unit_uk VARCHAR(32) DEFAULT NULL, unit_ru VARCHAR(32) DEFAULT NULL, max_qty INT DEFAULT 30 NOT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_8AE4E592F6BD1646 (site_id), INDEX idx_svc_calc_option_site (site_id, active, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_calc_type (id INT AUTO_INCREMENT NOT NULL, name_uk VARCHAR(128) NOT NULL, name_ru VARCHAR(128) DEFAULT NULL, desc_uk VARCHAR(255) DEFAULT NULL, desc_ru VARCHAR(255) DEFAULT NULL, price_per_sqm NUMERIC(10, 2) NOT NULL, min_price NUMERIC(10, 2) NOT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_1298B8EAF6BD1646 (site_id), INDEX idx_svc_calc_type_site (site_id, active, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_calc_factor ADD CONSTRAINT FK_3D5A0922F6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_calc_option ADD CONSTRAINT FK_8AE4E592F6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_calc_type ADD CONSTRAINT FK_1298B8EAF6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_calc_factor DROP FOREIGN KEY FK_3D5A0922F6BD1646');
        $this->addSql('ALTER TABLE service_calc_option DROP FOREIGN KEY FK_8AE4E592F6BD1646');
        $this->addSql('ALTER TABLE service_calc_type DROP FOREIGN KEY FK_1298B8EAF6BD1646');
        $this->addSql('DROP TABLE service_calc_factor');
        $this->addSql('DROP TABLE service_calc_option');
        $this->addSql('DROP TABLE service_calc_type');
    }
}
