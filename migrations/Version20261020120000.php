<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Поля форми заявки (20.10.2026): service_lead.details — додаткові поля (app.lead_fields паку), consent_at — час згоди
 * на обробку персональних даних (app.lead_consent). Обидві nullable: наявні заявки не змінюються.
 */
final class Version20261020120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_lead: details (JSON, додаткові поля форми), consent_at (згода на обробку ПД)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead ADD details JSON DEFAULT NULL, ADD consent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead DROP details, DROP consent_at');
    }
}
