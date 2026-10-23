<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Менеджер редиректів і журнал 404 в адмінці (23.10.2026, модель shop-engine E3): service_redirect — правила 301/302/404/410
 * сайту (на всі міста або на одне, city_key = id міста чи 0 для унікального індексу), service_not_found — журнал 404 за
 * хостом міста й шляхом. Адитивна: дві нові таблиці, наявні не змінюються. Без правил поведінка сайтів та сама, що до міграції.
 */
final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_redirect + service_not_found: менеджер редиректів і журнал 404 (SEO в адмінці)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_not_found (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, path VARCHAR(700) NOT NULL COLLATE `utf8mb4_bin`, hits INT UNSIGNED DEFAULT 1 NOT NULL, first_seen_at DATETIME NOT NULL, last_hit_at DATETIME NOT NULL, referer VARCHAR(500) DEFAULT NULL, site_id INT NOT NULL, city_id INT NOT NULL, INDEX IDX_50A1A4B4F6BD1646 (site_id), INDEX IDX_50A1A4B48BAC62AF (city_id), INDEX idx_service_not_found_last_hit (site_id, last_hit_at), UNIQUE INDEX uniq_service_not_found_path (site_id, city_id, path), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_redirect (id INT AUTO_INCREMENT NOT NULL, city_key INT UNSIGNED DEFAULT 0 NOT NULL, source VARCHAR(700) NOT NULL COLLATE `utf8mb4_bin`, target VARCHAR(2048) DEFAULT NULL, target_path VARCHAR(700) DEFAULT NULL COLLATE `utf8mb4_bin`, code SMALLINT NOT NULL, kind VARCHAR(8) NOT NULL, hits INT UNSIGNED DEFAULT 0 NOT NULL, last_hit_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, site_id INT NOT NULL, city_id INT DEFAULT NULL, INDEX IDX_3A7AC516F6BD1646 (site_id), INDEX IDX_3A7AC5168BAC62AF (city_id), INDEX idx_service_redirect_target_path (site_id, target_path), UNIQUE INDEX uniq_service_redirect_source (site_id, city_key, source), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_not_found ADD CONSTRAINT FK_50A1A4B4F6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_not_found ADD CONSTRAINT FK_50A1A4B48BAC62AF FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_redirect ADD CONSTRAINT FK_3A7AC516F6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_redirect ADD CONSTRAINT FK_3A7AC5168BAC62AF FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_not_found');
        $this->addSql('DROP TABLE service_redirect');
    }
}
