<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Стан відправки заявки в Pipedrive партнера. Порожній pipedrive_lead_id -
 * це і є черга: такі заявки добирає команда app:pipedrive:sync.
 */
final class Version20260907140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_lead: ід ліда в Pipedrive, час відправки й остання помилка';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead
            ADD pipedrive_lead_id VARCHAR(36) DEFAULT NULL,
            ADD pipedrive_synced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            ADD pipedrive_error VARCHAR(500) DEFAULT NULL');
        // черга читається за цим індексом: «де порожньо і свіже»
        $this->addSql('CREATE INDEX idx_svc_lead_pipedrive ON service_lead (pipedrive_lead_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_svc_lead_pipedrive ON service_lead');
        $this->addSql('ALTER TABLE service_lead
            DROP pipedrive_lead_id, DROP pipedrive_synced_at, DROP pipedrive_error');
    }
}
