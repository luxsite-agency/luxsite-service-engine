<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * legacy_id для прайсу, відгуків і лідів: ідемпотентний імпорт зі старої CMS (app:joomla:import)
 * упізнає вже перенесені записи й оновлює їх замість дублювання. Категорії, послуги, райони, пости
 * мали поле від початку.
 */
final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_price / service_review / service_lead: legacy_id для імпорту зі старої CMS';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_price ADD legacy_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_review ADD legacy_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_lead ADD legacy_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_price DROP legacy_id');
        $this->addSql('ALTER TABLE service_review DROP legacy_id');
        $this->addSql('ALTER TABLE service_lead DROP legacy_id');
    }
}
