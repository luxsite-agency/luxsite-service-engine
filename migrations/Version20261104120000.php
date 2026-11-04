<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Адитивний модуль партнерів: старі заявки й сайти залишаються без маршрутизації. */
final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Партнери міста, передачі заявок, ZIP і знімок згоди';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_partner (id INT AUTO_INCREMENT NOT NULL, city_id INT NOT NULL, name VARCHAR(128) NOT NULL, status VARCHAR(16) DEFAULT \'active\' NOT NULL, is_own TINYINT DEFAULT 0 NOT NULL, priority INT DEFAULT 0 NOT NULL, daily_limit INT DEFAULT NULL, coverage_zips JSON DEFAULT NULL, notify_emails VARCHAR(512) DEFAULT NULL, model VARCHAR(16) DEFAULT \'shared\' NOT NULL, price_per_lead INT DEFAULT NULL, license_notes LONGTEXT DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_svc_partner_city (city_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_partner ADD CONSTRAINT fk_svc_partner_city FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_lead ADD zip_from VARCHAR(10) DEFAULT NULL, ADD zip_to VARCHAR(10) DEFAULT NULL, ADD consent_text LONGTEXT DEFAULT NULL, ADD consent_ip VARCHAR(45) DEFAULT NULL, ADD routing VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_svc_lead_routing ON service_lead (routing)');
        $this->addSql('CREATE TABLE service_lead_assignment (id INT AUTO_INCREMENT NOT NULL, lead_id INT NOT NULL, partner_id INT NOT NULL, position SMALLINT NOT NULL, assigned_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, status VARCHAR(16) DEFAULT \'new\' NOT NULL, status_at DATETIME DEFAULT NULL, status_note VARCHAR(255) DEFAULT NULL, price INT DEFAULT NULL, UNIQUE INDEX uniq_svc_assignment_lead_partner (lead_id, partner_id), INDEX idx_svc_assignment_partner_assigned (partner_id, assigned_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_lead_assignment ADD CONSTRAINT fk_svc_assignment_lead FOREIGN KEY (lead_id) REFERENCES service_lead (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_lead_assignment ADD CONSTRAINT fk_svc_assignment_partner FOREIGN KEY (partner_id) REFERENCES service_partner (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_lead_assignment');
        $this->addSql('DROP TABLE service_partner');
        $this->addSql('DROP INDEX idx_svc_lead_routing ON service_lead');
        $this->addSql('ALTER TABLE service_lead DROP zip_from, DROP zip_to, DROP consent_text, DROP consent_ip, DROP routing');
    }
}
