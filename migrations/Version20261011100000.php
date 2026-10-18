<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Тексти під місто (10.2026, сайт із двома містами — дублі між хостами): у service_item_city — назва, SEO-мета, desc2/desc3,
 * назва вкладки; нова service_category_city; у service_city — власний текст «Про нас». Усе nullable, порожнє = як було.
 */
final class Version20261011100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Перевизначення текстів під місто: service_item_city (+12 полів), service_category_city (нова), service_city.about_*';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_item_city ADD title_uk VARCHAR(255) DEFAULT NULL, ADD title_ru VARCHAR(255) DEFAULT NULL, ADD meta_title_uk VARCHAR(255) DEFAULT NULL, ADD meta_title_ru VARCHAR(255) DEFAULT NULL, ADD meta_desc_uk LONGTEXT DEFAULT NULL, ADD meta_desc_ru LONGTEXT DEFAULT NULL, ADD desc2_uk LONGTEXT DEFAULT NULL, ADD desc2_ru LONGTEXT DEFAULT NULL, ADD desc3_uk LONGTEXT DEFAULT NULL, ADD desc3_ru LONGTEXT DEFAULT NULL, ADD tab_title_uk VARCHAR(255) DEFAULT NULL, ADD tab_title_ru VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE TABLE service_category_city (id INT AUTO_INCREMENT NOT NULL, category_id INT NOT NULL, city_id INT NOT NULL, title_uk VARCHAR(255) DEFAULT NULL, title_ru VARCHAR(255) DEFAULT NULL, meta_title_uk VARCHAR(255) DEFAULT NULL, meta_title_ru VARCHAR(255) DEFAULT NULL, meta_desc_uk LONGTEXT DEFAULT NULL, meta_desc_ru LONGTEXT DEFAULT NULL, desc_uk LONGTEXT DEFAULT NULL, desc_ru LONGTEXT DEFAULT NULL, desc2_uk LONGTEXT DEFAULT NULL, desc2_ru LONGTEXT DEFAULT NULL, desc3_uk LONGTEXT DEFAULT NULL, desc3_ru LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, INDEX IDX_SCC_CATEGORY (category_id), INDEX IDX_SCC_CITY (city_id), UNIQUE INDEX uniq_category_city (category_id, city_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE service_category_city ADD CONSTRAINT FK_SCC_CATEGORY FOREIGN KEY (category_id) REFERENCES service_category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_category_city ADD CONSTRAINT FK_SCC_CITY FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_city ADD about_uk LONGTEXT DEFAULT NULL, ADD about_ru LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city DROP about_uk, DROP about_ru');
        $this->addSql('DROP TABLE service_category_city');
        $this->addSql('ALTER TABLE service_item_city DROP title_uk, DROP title_ru, DROP meta_title_uk, DROP meta_title_ru, DROP meta_desc_uk, DROP meta_desc_ru, DROP desc2_uk, DROP desc2_ru, DROP desc3_uk, DROP desc3_ru, DROP tab_title_uk, DROP tab_title_ru');
    }
}
