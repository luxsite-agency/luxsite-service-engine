<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Мови бренду (22.10.2026): service_site.locales — підмножина мов розгортання app.locales. Адитивна: nullable-колонка,
 * NULL = усі мови розгортання, тож наявні бренди працюють як до міграції.
 */
final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_site.locales: мови бренду (NULL = усі мови розгортання)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_site ADD locales JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_site DROP locales');
    }
}
