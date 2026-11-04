<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Вебхуки партнерів і черга повторної доставки email/вебхуків';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_partner ADD webhook_url VARCHAR(512) DEFAULT NULL, ADD webhook_secret LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_lead_assignment ADD webhook_delivered_at DATETIME DEFAULT NULL, ADD attempts SMALLINT DEFAULT 0 NOT NULL, ADD next_attempt_at DATETIME DEFAULT NULL, ADD last_error VARCHAR(500) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_svc_assignment_next_attempt ON service_lead_assignment (next_attempt_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_svc_assignment_next_attempt ON service_lead_assignment');
        $this->addSql('ALTER TABLE service_lead_assignment DROP webhook_delivered_at, DROP attempts, DROP next_attempt_at, DROP last_error');
        $this->addSql('ALTER TABLE service_partner DROP webhook_url, DROP webhook_secret');
    }
}
