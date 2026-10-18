<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260830211601 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Початкова схема платформи сервісних сайтів: user + service_* (тенанти, каталог, ціни, райони, відгуки, команда, блог, заявки)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE service_category (id INT AUTO_INCREMENT NOT NULL, legacy_id INT DEFAULT NULL, slug_uk VARCHAR(64) NOT NULL, slug_ru VARCHAR(64) DEFAULT NULL, title_uk VARCHAR(255) NOT NULL, title_ru VARCHAR(255) DEFAULT NULL, desc_uk LONGTEXT DEFAULT NULL, desc_ru LONGTEXT DEFAULT NULL, desc2_uk LONGTEXT DEFAULT NULL, desc2_ru LONGTEXT DEFAULT NULL, desc3_uk LONGTEXT DEFAULT NULL, desc3_ru LONGTEXT DEFAULT NULL, meta_title_uk VARCHAR(255) DEFAULT NULL, meta_title_ru VARCHAR(255) DEFAULT NULL, meta_desc_uk LONGTEXT DEFAULT NULL, meta_desc_ru LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, image_name VARCHAR(255) DEFAULT NULL, image_alt VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_FF3A42FCF6BD1646 (site_id), UNIQUE INDEX uniq_svc_cat_site_slug_uk (site_id, slug_uk), UNIQUE INDEX uniq_svc_cat_site_slug_ru (site_id, slug_ru), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_city (id INT AUTO_INCREMENT NOT NULL, host VARCHAR(128) NOT NULL, slug VARCHAR(64) NOT NULL, is_apex TINYINT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, phone VARCHAR(32) DEFAULT NULL, email VARCHAR(128) DEFAULT NULL, hours VARCHAR(64) DEFAULT NULL, gtm_id VARCHAR(16) DEFAULT NULL, name_nom_uk VARCHAR(64) NOT NULL, name_gen_uk VARCHAR(64) DEFAULT NULL, name_dat_uk VARCHAR(64) DEFAULT NULL, name_loc_uk VARCHAR(64) DEFAULT NULL, name_nom_ru VARCHAR(64) DEFAULT NULL, name_gen_ru VARCHAR(64) DEFAULT NULL, name_dat_ru VARCHAR(64) DEFAULT NULL, name_loc_ru VARCHAR(64) DEFAULT NULL, meta_title_uk VARCHAR(255) DEFAULT NULL, meta_title_ru VARCHAR(255) DEFAULT NULL, meta_desc_uk LONGTEXT DEFAULT NULL, meta_desc_ru LONGTEXT DEFAULT NULL, intro_title_uk VARCHAR(255) DEFAULT NULL, intro_title_ru VARCHAR(255) DEFAULT NULL, intro_text_uk LONGTEXT DEFAULT NULL, intro_text_ru LONGTEXT DEFAULT NULL, text2_uk LONGTEXT DEFAULT NULL, text2_ru LONGTEXT DEFAULT NULL, text3_uk LONGTEXT DEFAULT NULL, text3_ru LONGTEXT DEFAULT NULL, faq_uk JSON DEFAULT NULL, faq_ru JSON DEFAULT NULL, districts_intro_uk LONGTEXT DEFAULT NULL, districts_intro_ru LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, UNIQUE INDEX UNIQ_E318B6D8CF2713FD (host), INDEX IDX_E318B6D8F6BD1646 (site_id), UNIQUE INDEX uniq_svc_city_site_slug (site_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_district (id INT AUTO_INCREMENT NOT NULL, legacy_id INT DEFAULT NULL, slug_uk VARCHAR(64) NOT NULL, slug_ru VARCHAR(64) DEFAULT NULL, name_uk VARCHAR(128) NOT NULL, name_ru VARCHAR(128) DEFAULT NULL, title_uk VARCHAR(255) DEFAULT NULL, title_ru VARCHAR(255) DEFAULT NULL, lead_uk LONGTEXT DEFAULT NULL, lead_ru LONGTEXT DEFAULT NULL, desc_uk LONGTEXT DEFAULT NULL, desc_ru LONGTEXT DEFAULT NULL, meta_title_uk VARCHAR(255) DEFAULT NULL, meta_title_ru VARCHAR(255) DEFAULT NULL, meta_desc_uk LONGTEXT DEFAULT NULL, meta_desc_ru LONGTEXT DEFAULT NULL, min_price INT DEFAULT NULL, sub_areas_uk LONGTEXT DEFAULT NULL, sub_areas_ru LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, city_id INT NOT NULL, INDEX IDX_C8B70FBA8BAC62AF (city_id), UNIQUE INDEX uniq_svc_district_city_slug_uk (city_id, slug_uk), UNIQUE INDEX uniq_svc_district_city_slug_ru (city_id, slug_ru), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_item (id INT AUTO_INCREMENT NOT NULL, legacy_id INT DEFAULT NULL, slug_uk VARCHAR(64) NOT NULL, slug_ru VARCHAR(64) DEFAULT NULL, title_uk VARCHAR(255) NOT NULL, title_ru VARCHAR(255) DEFAULT NULL, lead_uk LONGTEXT DEFAULT NULL, lead_ru LONGTEXT DEFAULT NULL, desc_uk LONGTEXT DEFAULT NULL, desc_ru LONGTEXT DEFAULT NULL, desc2_uk LONGTEXT DEFAULT NULL, desc2_ru LONGTEXT DEFAULT NULL, desc3_uk LONGTEXT DEFAULT NULL, desc3_ru LONGTEXT DEFAULT NULL, tab_title_uk VARCHAR(255) DEFAULT NULL, tab_title_ru VARCHAR(255) DEFAULT NULL, meta_title_uk VARCHAR(255) DEFAULT NULL, meta_title_ru VARCHAR(255) DEFAULT NULL, meta_desc_uk LONGTEXT DEFAULT NULL, meta_desc_ru LONGTEXT DEFAULT NULL, faq_uk JSON DEFAULT NULL, faq_ru JSON DEFAULT NULL, utp TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, image_name VARCHAR(255) DEFAULT NULL, image_alt VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_D15891F2F6BD1646 (site_id), INDEX IDX_D15891F212469DE2 (category_id), INDEX idx_svc_item_cat_active (category_id, active, position), UNIQUE INDEX uniq_svc_item_site_slug_uk (site_id, slug_uk), UNIQUE INDEX uniq_svc_item_site_slug_ru (site_id, slug_ru), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_lead (id INT AUTO_INCREMENT NOT NULL, phone VARCHAR(32) NOT NULL, name VARCHAR(128) DEFAULT NULL, message LONGTEXT DEFAULT NULL, page_url VARCHAR(512) DEFAULT NULL, locale VARCHAR(2) DEFAULT \'uk\' NOT NULL, processed TINYINT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, city_id INT NOT NULL, INDEX IDX_E6D2D5278BAC62AF (city_id), INDEX idx_svc_lead_city_processed (city_id, processed, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_post (id INT AUTO_INCREMENT NOT NULL, legacy_id INT DEFAULT NULL, slug_uk VARCHAR(64) NOT NULL, slug_ru VARCHAR(64) DEFAULT NULL, title_uk VARCHAR(255) NOT NULL, title_ru VARCHAR(255) DEFAULT NULL, lead_uk LONGTEXT DEFAULT NULL, lead_ru LONGTEXT DEFAULT NULL, desc_uk LONGTEXT DEFAULT NULL, desc_ru LONGTEXT DEFAULT NULL, meta_title_uk VARCHAR(255) DEFAULT NULL, meta_title_ru VARCHAR(255) DEFAULT NULL, meta_desc_uk LONGTEXT DEFAULT NULL, meta_desc_ru LONGTEXT DEFAULT NULL, published_at DATETIME DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, image_name VARCHAR(255) DEFAULT NULL, image_alt VARCHAR(255) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_94C9D861F6BD1646 (site_id), INDEX idx_svc_post_site_published (site_id, active, published_at), UNIQUE INDEX uniq_svc_post_site_slug_uk (site_id, slug_uk), UNIQUE INDEX uniq_svc_post_site_slug_ru (site_id, slug_ru), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_price (id INT AUTO_INCREMENT NOT NULL, name_uk VARCHAR(255) NOT NULL, name_ru VARCHAR(255) DEFAULT NULL, desc_uk LONGTEXT DEFAULT NULL, desc_ru LONGTEXT DEFAULT NULL, price NUMERIC(10, 2) DEFAULT NULL, price_prefix VARCHAR(16) DEFAULT NULL, price_text VARCHAR(64) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, item_id INT NOT NULL, city_id INT NOT NULL, INDEX IDX_63BACF3E126F525E (item_id), INDEX IDX_63BACF3E8BAC62AF (city_id), INDEX idx_svc_price_item_city (item_id, city_id, active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_review (id INT AUTO_INCREMENT NOT NULL, author VARCHAR(128) NOT NULL, text_uk LONGTEXT NOT NULL, text_ru LONGTEXT DEFAULT NULL, stars SMALLINT DEFAULT 5 NOT NULL, reviewed_at DATE DEFAULT NULL, image_name VARCHAR(255) DEFAULT NULL, image_alt VARCHAR(255) DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, city_id INT NOT NULL, INDEX IDX_478484F08BAC62AF (city_id), INDEX idx_svc_review_city_active (city_id, active), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_site (id INT AUTO_INCREMENT NOT NULL, slug VARCHAR(64) NOT NULL, name VARCHAR(128) NOT NULL, apex_domain VARCHAR(128) NOT NULL, schema_type VARCHAR(64) DEFAULT \'LocalBusiness\' NOT NULL, active TINYINT DEFAULT 1 NOT NULL, about_title_uk VARCHAR(255) DEFAULT NULL, about_title_ru VARCHAR(255) DEFAULT NULL, about_uk LONGTEXT DEFAULT NULL, about_ru LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_A700BD08989D9B62 (slug), UNIQUE INDEX UNIQ_A700BD08A62315B4 (apex_domain), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_team_member (id INT AUTO_INCREMENT NOT NULL, name_uk VARCHAR(128) NOT NULL, name_ru VARCHAR(128) DEFAULT NULL, role_uk VARCHAR(255) DEFAULT NULL, role_ru VARCHAR(255) DEFAULT NULL, desc_uk LONGTEXT DEFAULT NULL, desc_ru LONGTEXT DEFAULT NULL, image_name VARCHAR(255) DEFAULT NULL, image_alt VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, city_id INT NOT NULL, INDEX IDX_CA659B0D8BAC62AF (city_id), INDEX idx_svc_team_city_active (city_id, active, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(190) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, active TINYINT DEFAULT 1 NOT NULL, google_id VARCHAR(255) DEFAULT NULL, display_name VARCHAR(128) DEFAULT NULL, avatar_url VARCHAR(512) DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), UNIQUE INDEX UNIQ_8D93D64976F5C865 (google_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_category ADD CONSTRAINT FK_FF3A42FCF6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_city ADD CONSTRAINT FK_E318B6D8F6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_district ADD CONSTRAINT FK_C8B70FBA8BAC62AF FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_item ADD CONSTRAINT FK_D15891F2F6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_item ADD CONSTRAINT FK_D15891F212469DE2 FOREIGN KEY (category_id) REFERENCES service_category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_lead ADD CONSTRAINT FK_E6D2D5278BAC62AF FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_post ADD CONSTRAINT FK_94C9D861F6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_price ADD CONSTRAINT FK_63BACF3E126F525E FOREIGN KEY (item_id) REFERENCES service_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_price ADD CONSTRAINT FK_63BACF3E8BAC62AF FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_review ADD CONSTRAINT FK_478484F08BAC62AF FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_team_member ADD CONSTRAINT FK_CA659B0D8BAC62AF FOREIGN KEY (city_id) REFERENCES service_city (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE service_category DROP FOREIGN KEY FK_FF3A42FCF6BD1646');
        $this->addSql('ALTER TABLE service_city DROP FOREIGN KEY FK_E318B6D8F6BD1646');
        $this->addSql('ALTER TABLE service_district DROP FOREIGN KEY FK_C8B70FBA8BAC62AF');
        $this->addSql('ALTER TABLE service_item DROP FOREIGN KEY FK_D15891F2F6BD1646');
        $this->addSql('ALTER TABLE service_item DROP FOREIGN KEY FK_D15891F212469DE2');
        $this->addSql('ALTER TABLE service_lead DROP FOREIGN KEY FK_E6D2D5278BAC62AF');
        $this->addSql('ALTER TABLE service_post DROP FOREIGN KEY FK_94C9D861F6BD1646');
        $this->addSql('ALTER TABLE service_price DROP FOREIGN KEY FK_63BACF3E126F525E');
        $this->addSql('ALTER TABLE service_price DROP FOREIGN KEY FK_63BACF3E8BAC62AF');
        $this->addSql('ALTER TABLE service_review DROP FOREIGN KEY FK_478484F08BAC62AF');
        $this->addSql('ALTER TABLE service_team_member DROP FOREIGN KEY FK_CA659B0D8BAC62AF');
        $this->addSql('DROP TABLE service_category');
        $this->addSql('DROP TABLE service_city');
        $this->addSql('DROP TABLE service_district');
        $this->addSql('DROP TABLE service_item');
        $this->addSql('DROP TABLE service_lead');
        $this->addSql('DROP TABLE service_post');
        $this->addSql('DROP TABLE service_price');
        $this->addSql('DROP TABLE service_review');
        $this->addSql('DROP TABLE service_site');
        $this->addSql('DROP TABLE service_team_member');
        $this->addSql('DROP TABLE `user`');
    }
}
