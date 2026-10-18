<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * service_post: слаги до 160 символів — у Joomla-блогах аліаси довгі (до 80 на першому імпортованому сайті),
 * а URL постів при міграції мають лишитися 1:1.
 */
final class Version20261010120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_post: slug_uk/slug_ru VARCHAR(160) під довгі аліаси Joomla';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_post CHANGE slug_uk slug_uk VARCHAR(160) NOT NULL, CHANGE slug_ru slug_ru VARCHAR(160) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_post CHANGE slug_uk slug_uk VARCHAR(64) NOT NULL, CHANGE slug_ru slug_ru VARCHAR(64) DEFAULT NULL');
    }
}
