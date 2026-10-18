<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Обкладинка сторінки району. Порожнє поле - не помилка: шаблон бере фото
 * з загального пулу за id, а колонка потрібна там, де для конкретного району
 * хочеться поставити своє.
 */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_district: image_name і image_alt';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_district
            ADD image_name VARCHAR(255) DEFAULT NULL,
            ADD image_alt VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_district DROP image_name, DROP image_alt');
    }
}
