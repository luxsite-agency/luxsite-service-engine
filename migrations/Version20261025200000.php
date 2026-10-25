<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Дата змістовного оновлення статті (25.10.2026): service_post.content_updated_at, NULL — не оновлювалась. dateModified і
 * «Updated» беруть її, а не технічний updated_at (імпорт зсував його на дату імпорту — однаковий dateModified у всіх статей).
 * Адитивна: наявні статті отримують NULL, тобто dateModified = дата публікації.
 */
final class Version20261025200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_post.content_updated_at: дата змістовного оновлення статті';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_post ADD content_updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_post DROP content_updated_at');
    }
}
