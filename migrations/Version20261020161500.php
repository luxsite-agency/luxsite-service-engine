<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Сторінки від кореня (app.pages, 20.10.2026): service_page (батько, слаги uk/ru, тексти, SEO, обкладинка, extra),
 * service_page_hidden_city, service_page_image (галерея). Слаги послуг і категорій 64 → 160: старі CMS мають адреси
 * /<слаг>/ довші за 64 символи (схема slug зберігає їх 1:1). Адитивно: реліз без цієї міграції працює з новою схемою.
 */
final class Version20261020161500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_page, service_page_hidden_city, service_page_image; service_item/service_category slug VARCHAR(160)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_page (id INT AUTO_INCREMENT NOT NULL, slug_uk VARCHAR(160) NOT NULL, slug_ru VARCHAR(160) DEFAULT NULL, title_uk VARCHAR(255) NOT NULL, title_ru VARCHAR(255) DEFAULT NULL, lead_uk LONGTEXT DEFAULT NULL, lead_ru LONGTEXT DEFAULT NULL, desc_uk LONGTEXT DEFAULT NULL, desc_ru LONGTEXT DEFAULT NULL, meta_title_uk VARCHAR(255) DEFAULT NULL, meta_title_ru VARCHAR(255) DEFAULT NULL, meta_desc_uk LONGTEXT DEFAULT NULL, meta_desc_ru LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, in_footer TINYINT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, image_name VARCHAR(255) DEFAULT NULL, image_alt VARCHAR(255) DEFAULT NULL, extra JSON DEFAULT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_DA4902CCF6BD1646 (site_id), INDEX IDX_DA4902CC727ACA70 (parent_id), INDEX idx_svc_page_site_active (site_id, active, position), UNIQUE INDEX uniq_svc_page_site_slug_uk (site_id, slug_uk), UNIQUE INDEX uniq_svc_page_site_slug_ru (site_id, slug_ru), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_page_hidden_city (service_page_id INT NOT NULL, service_city_id INT NOT NULL, INDEX IDX_149966FBC3C23178 (service_page_id), INDEX IDX_149966FB44283033 (service_city_id), PRIMARY KEY (service_page_id, service_city_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_page_image (id INT AUTO_INCREMENT NOT NULL, image_name VARCHAR(255) DEFAULT NULL, caption_uk VARCHAR(255) DEFAULT NULL, caption_ru VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, page_id INT NOT NULL, INDEX IDX_35EDC23DC4663E4 (page_id), INDEX idx_svc_page_image_page (page_id, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_page ADD CONSTRAINT FK_DA4902CCF6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_page ADD CONSTRAINT FK_DA4902CC727ACA70 FOREIGN KEY (parent_id) REFERENCES service_page (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_page_hidden_city ADD CONSTRAINT FK_149966FBC3C23178 FOREIGN KEY (service_page_id) REFERENCES service_page (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_page_hidden_city ADD CONSTRAINT FK_149966FB44283033 FOREIGN KEY (service_city_id) REFERENCES service_city (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_page_image ADD CONSTRAINT FK_35EDC23DC4663E4 FOREIGN KEY (page_id) REFERENCES service_page (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_category CHANGE slug_uk slug_uk VARCHAR(160) NOT NULL, CHANGE slug_ru slug_ru VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_item CHANGE slug_uk slug_uk VARCHAR(160) NOT NULL, CHANGE slug_ru slug_ru VARCHAR(160) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // слаги довші за 64 символи MySQL (strict) не обріже мовчки — down упаде, і це краще, ніж зламані адреси
        $this->addSql('ALTER TABLE service_item CHANGE slug_uk slug_uk VARCHAR(64) NOT NULL, CHANGE slug_ru slug_ru VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_category CHANGE slug_uk slug_uk VARCHAR(64) NOT NULL, CHANGE slug_ru slug_ru VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_page_image DROP FOREIGN KEY FK_35EDC23DC4663E4');
        $this->addSql('ALTER TABLE service_page_hidden_city DROP FOREIGN KEY FK_149966FB44283033');
        $this->addSql('ALTER TABLE service_page_hidden_city DROP FOREIGN KEY FK_149966FBC3C23178');
        $this->addSql('ALTER TABLE service_page DROP FOREIGN KEY FK_DA4902CC727ACA70');
        $this->addSql('ALTER TABLE service_page DROP FOREIGN KEY FK_DA4902CCF6BD1646');
        $this->addSql('DROP TABLE service_page_image');
        $this->addSql('DROP TABLE service_page_hidden_city');
        $this->addSql('DROP TABLE service_page');
    }
}
