<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Налаштування сайту в адмінці (21.10.2026, модель shop-engine): ключ → значення на сайт; секрети — конвертом SecretBox.
 * Адитивна: нова таблиця, наявні не змінюються. Без рядків поведінка сайтів та сама, що до міграції.
 */
final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_site_setting: налаштування сайту (інтеграції, аналітика, вхід через Google)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_site_setting (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, code VARCHAR(64) NOT NULL, value LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_site_setting_code (site_id, code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_site_setting ADD CONSTRAINT FK_BB7C2FB6F6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_site_setting');
    }
}
