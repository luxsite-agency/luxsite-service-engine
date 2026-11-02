<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Анкети (02.11.2026, app.applications): service_application (форма, ім'я, телефон, email, поля data, згода, сторінка);
 * файли анкети — service_lead_file.application_id. Адитивно.
 */
final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_application; service_lead_file.application_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_application (id INT AUTO_INCREMENT NOT NULL, form VARCHAR(32) NOT NULL, name VARCHAR(128) DEFAULT NULL, phone VARCHAR(32) DEFAULT NULL, email VARCHAR(191) DEFAULT NULL, data JSON NOT NULL, consent TINYINT DEFAULT 0 NOT NULL, page_url VARCHAR(512) DEFAULT NULL, locale VARCHAR(2) DEFAULT \'uk\' NOT NULL, processed TINYINT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, city_id INT NOT NULL, INDEX IDX_68C1FB6D8BAC62AF (city_id), INDEX idx_svc_application_city_form (city_id, form, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_application ADD CONSTRAINT FK_68C1FB6D8BAC62AF FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_lead_file ADD application_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_lead_file ADD CONSTRAINT FK_35F849803E030ACD FOREIGN KEY (application_id) REFERENCES service_application (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_35F849803E030ACD ON service_lead_file (application_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_application DROP FOREIGN KEY FK_68C1FB6D8BAC62AF');
        $this->addSql('DROP TABLE service_application');
        $this->addSql('ALTER TABLE service_lead_file DROP FOREIGN KEY FK_35F849803E030ACD');
        $this->addSql('DROP INDEX IDX_35F849803E030ACD ON service_lead_file');
        $this->addSql('ALTER TABLE service_lead_file DROP application_id');
    }
}
