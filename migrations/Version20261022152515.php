<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Контентний сайт (22.10.2026): service_site.mode (services за замовчуванням — наявні сайти без змін), рубрики
 * (service_post_category) і автори (service_author) блогу, service_post.category/author (ON DELETE SET NULL) і featured,
 * service_lead.email (форма зв'язку). Адитивна: нові таблиці й колонки з дефолтами, наявні рядки не змінюються.
 */
final class Version20261022152515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Контентний сайт: service_site.mode, рубрики й автори блогу, service_lead.email';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_author (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, role_uk VARCHAR(255) DEFAULT NULL, role_ru VARCHAR(255) DEFAULT NULL, role_en VARCHAR(255) DEFAULT NULL, bio_uk LONGTEXT DEFAULT NULL, bio_ru LONGTEXT DEFAULT NULL, bio_en LONGTEXT DEFAULT NULL, image_name VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_8368DDFEF6BD1646 (site_id), UNIQUE INDEX uniq_svc_author_site_slug (site_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_post_category (id INT AUTO_INCREMENT NOT NULL, slug_uk VARCHAR(160) DEFAULT NULL, slug_ru VARCHAR(160) DEFAULT NULL, slug_en VARCHAR(160) DEFAULT NULL, title_uk VARCHAR(255) DEFAULT NULL, title_ru VARCHAR(255) DEFAULT NULL, title_en VARCHAR(255) DEFAULT NULL, desc_uk LONGTEXT DEFAULT NULL, desc_ru LONGTEXT DEFAULT NULL, desc_en LONGTEXT DEFAULT NULL, meta_title_uk VARCHAR(255) DEFAULT NULL, meta_title_ru VARCHAR(255) DEFAULT NULL, meta_title_en VARCHAR(255) DEFAULT NULL, meta_desc_uk LONGTEXT DEFAULT NULL, meta_desc_ru LONGTEXT DEFAULT NULL, meta_desc_en LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_691E2AE2F6BD1646 (site_id), UNIQUE INDEX uniq_svc_post_cat_site_slug_uk (site_id, slug_uk), UNIQUE INDEX uniq_svc_post_cat_site_slug_ru (site_id, slug_ru), UNIQUE INDEX uniq_svc_post_cat_site_slug_en (site_id, slug_en), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_author ADD CONSTRAINT FK_8368DDFEF6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_post_category ADD CONSTRAINT FK_691E2AE2F6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_lead ADD email VARCHAR(190) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_post ADD featured TINYINT DEFAULT 0 NOT NULL, ADD category_id INT DEFAULT NULL, ADD author_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_post ADD CONSTRAINT FK_94C9D86112469DE2 FOREIGN KEY (category_id) REFERENCES service_post_category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_post ADD CONSTRAINT FK_94C9D861F675F31B FOREIGN KEY (author_id) REFERENCES service_author (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_94C9D86112469DE2 ON service_post (category_id)');
        $this->addSql('CREATE INDEX IDX_94C9D861F675F31B ON service_post (author_id)');
        $this->addSql('ALTER TABLE service_site ADD mode VARCHAR(16) DEFAULT \'services\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_post DROP FOREIGN KEY FK_94C9D86112469DE2');
        $this->addSql('ALTER TABLE service_post DROP FOREIGN KEY FK_94C9D861F675F31B');
        $this->addSql('DROP INDEX IDX_94C9D86112469DE2 ON service_post');
        $this->addSql('DROP INDEX IDX_94C9D861F675F31B ON service_post');
        $this->addSql('ALTER TABLE service_post DROP featured, DROP category_id, DROP author_id');
        $this->addSql('ALTER TABLE service_author DROP FOREIGN KEY FK_8368DDFEF6BD1646');
        $this->addSql('ALTER TABLE service_post_category DROP FOREIGN KEY FK_691E2AE2F6BD1646');
        $this->addSql('DROP TABLE service_author');
        $this->addSql('DROP TABLE service_post_category');
        $this->addSql('ALTER TABLE service_lead DROP email');
        $this->addSql('ALTER TABLE service_site DROP mode');
    }
}
