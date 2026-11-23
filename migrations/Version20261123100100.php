<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ServiceLead: поля лідгену (послуга, місто/ZIP, терміни, власник житла, згода, контекст).
 */
final class Version20261123100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_lead: service, town_zip, timeline, context, owner_occupied, consent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead ADD service VARCHAR(255) DEFAULT NULL, ADD town_zip VARCHAR(64) DEFAULT NULL, ADD timeline VARCHAR(64) DEFAULT NULL, ADD context VARCHAR(255) DEFAULT NULL, ADD owner_occupied TINYINT DEFAULT NULL, ADD consent TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead DROP service, DROP town_zip, DROP timeline, DROP context, DROP owner_occupied, DROP consent');
    }
}
