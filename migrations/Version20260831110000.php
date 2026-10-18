<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Локалізація одномовних полів (аудит перекладів): price_text і author стають
 * парами uk/ru. RENAME COLUMN зберігає дані (ручна міграція — diff зробив би
 * DROP+ADD із втратою).
 */
final class Version20260831110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ServicePrice.priceText і ServiceReview.author → пари uk/ru (збереження даних через RENAME COLUMN)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_price RENAME COLUMN price_text TO price_text_uk, ADD price_text_ru VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_review RENAME COLUMN author TO author_uk, ADD author_ru VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_price RENAME COLUMN price_text_uk TO price_text, DROP price_text_ru');
        $this->addSql('ALTER TABLE service_review RENAME COLUMN author_uk TO author, DROP author_ru');
    }
}
