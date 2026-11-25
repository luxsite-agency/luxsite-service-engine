<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nullable English districts intro, about location, price note and address for service_city';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city ADD districts_intro_en LONGTEXT DEFAULT NULL, ADD about_where_en LONGTEXT DEFAULT NULL, ADD price_note_en VARCHAR(255) DEFAULT NULL, ADD address_en VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city DROP districts_intro_en, DROP about_where_en, DROP price_note_en, DROP address_en');
    }
}
