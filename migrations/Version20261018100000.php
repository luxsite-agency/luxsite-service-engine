<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Коротка назва бренду сайту uk/ru (18.10.2026): %site% у «Відгуки клієнтів про компанію %site%»; порожньо — app.brand_name / назва. */
final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_site: brand_name_uk, brand_name_ru';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_site ADD brand_name_uk VARCHAR(128) DEFAULT NULL, ADD brand_name_ru VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_site DROP brand_name_uk, DROP brand_name_ru');
    }
}
