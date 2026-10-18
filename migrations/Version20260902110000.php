<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Кнопка «Відкрити на Google Картах» поруч із вбудованою картою. */
final class Version20260902110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_city: map_link_url';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city ADD map_link_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city DROP map_link_url');
    }
}
