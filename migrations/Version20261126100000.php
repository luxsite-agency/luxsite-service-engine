<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Галерея сторінки app.pages: англійський підпис зображення (caption_en) — як у ServiceWork. Порожній = український підпис.
 */
final class Version20261126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_page_image: caption_en';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_page_image ADD caption_en VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_page_image DROP caption_en');
    }
}
