<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Розділ статей на бренд (24.10.2026): service_site.blog_prefix, NULL = app.blog_prefix. Адитивна: наявні бренди
 * отримують NULL, їхні адреси не змінюються.
 */
final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_site.blog_prefix: розділ статей бренду (NULL = app.blog_prefix)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_site ADD blog_prefix VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_site DROP blog_prefix');
    }
}
