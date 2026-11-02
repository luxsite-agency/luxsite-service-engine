<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Заявка (02.11.2026): окремі поля service і context; файли заявки service_lead_file (app.lead_files, зберігання поза
 * public/, видалення разом із заявкою). Адитивно: попередній реліз на новій схемі працює.
 */
final class Version20261102110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_lead: service, context; service_lead_file';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_lead_file (id INT AUTO_INCREMENT NOT NULL, original_name VARCHAR(255) NOT NULL, stored_name VARCHAR(64) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, created_at DATETIME NOT NULL, lead_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_35F849801185AF6A (stored_name), INDEX IDX_35F8498055458D (lead_id), INDEX idx_svc_lead_file_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_lead_file ADD CONSTRAINT FK_35F8498055458D FOREIGN KEY (lead_id) REFERENCES service_lead (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_lead ADD service VARCHAR(255) DEFAULT NULL, ADD context VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead_file DROP FOREIGN KEY FK_35F8498055458D');
        $this->addSql('DROP TABLE service_lead_file');
        $this->addSql('ALTER TABLE service_lead DROP service, DROP context');
    }
}
