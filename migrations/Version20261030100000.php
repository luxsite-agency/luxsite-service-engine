<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Рядок для окремого міста (30.10.2026): service_city.price_note_uk/ru — «Мінімальний виклик — від 1500 грн» лише в одному
 * місті сайту (під цінами, у калькуляторі, біля форми заявки). Адитивна: дві nullable-колонки, NULL = рядка нема, як до міграції.
 */
final class Version20261030100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_city.price_note_uk/ru: рядок про ціни лише для цього міста';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city ADD price_note_uk VARCHAR(255) DEFAULT NULL, ADD price_note_ru VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city DROP price_note_uk, DROP price_note_ru');
    }
}
