<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Місто в розробці: повністю закрите від пошуковиків. */
final class Version20260902120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_city: noindex';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city ADD noindex TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city DROP noindex');
    }
}
