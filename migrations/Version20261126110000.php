<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Заявка: email з форми (необов'язкове поле POST /lead) і довільні поля, дозволені паком (app.lead.extra_fields) — JSON.
 */
final class Version20261126110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_lead: email, extra (JSON)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead ADD email VARCHAR(180) DEFAULT NULL, ADD extra JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead DROP email, DROP extra');
    }
}
