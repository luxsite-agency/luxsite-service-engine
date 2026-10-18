<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Офіційний район міста - верхній рівень лінк-мережі районних сторінок. */
final class Version20260902130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_district: is_primary';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_district ADD is_primary TINYINT(1) DEFAULT 0 NOT NULL');
        // офіційні райони - єдині локації, у назві яких є слово «район»
        $this->addSql("UPDATE service_district SET is_primary = 1 WHERE name_uk LIKE '%район%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_district DROP is_primary');
    }
}
