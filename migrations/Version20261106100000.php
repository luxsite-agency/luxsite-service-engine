<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nullable English catalog, district, page and content block fields with scoped slug indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_item ADD slug_en VARCHAR(160) DEFAULT NULL, ADD title_en VARCHAR(255) DEFAULT NULL, ADD lead_en LONGTEXT DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL, ADD desc2_en LONGTEXT DEFAULT NULL, ADD desc3_en LONGTEXT DEFAULT NULL, ADD tab_title_en VARCHAR(255) DEFAULT NULL, ADD name_en VARCHAR(255) DEFAULT NULL, ADD meta_title_en VARCHAR(255) DEFAULT NULL, ADD meta_desc_en LONGTEXT DEFAULT NULL, ADD faq_en JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE service_category ADD slug_en VARCHAR(160) DEFAULT NULL, ADD title_en VARCHAR(255) DEFAULT NULL, ADD lead_en LONGTEXT DEFAULT NULL, ADD faq_en JSON DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL, ADD desc2_en LONGTEXT DEFAULT NULL, ADD desc3_en LONGTEXT DEFAULT NULL, ADD meta_title_en VARCHAR(255) DEFAULT NULL, ADD meta_desc_en LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_district ADD slug_en VARCHAR(64) DEFAULT NULL, ADD group_en VARCHAR(128) DEFAULT NULL, ADD name_en VARCHAR(128) DEFAULT NULL, ADD title_en VARCHAR(255) DEFAULT NULL, ADD lead_en LONGTEXT DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL, ADD meta_title_en VARCHAR(255) DEFAULT NULL, ADD meta_desc_en LONGTEXT DEFAULT NULL, ADD sub_areas_en LONGTEXT DEFAULT NULL, ADD faq_en JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE service_page ADD slug_en VARCHAR(160) DEFAULT NULL, ADD title_en VARCHAR(255) DEFAULT NULL, ADD lead_en LONGTEXT DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL, ADD meta_title_en VARCHAR(255) DEFAULT NULL, ADD meta_desc_en LONGTEXT DEFAULT NULL, ADD h1_en VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_item_city ADD title_en VARCHAR(255) DEFAULT NULL, ADD meta_title_en VARCHAR(255) DEFAULT NULL, ADD meta_desc_en LONGTEXT DEFAULT NULL, ADD desc2_en LONGTEXT DEFAULT NULL, ADD desc3_en LONGTEXT DEFAULT NULL, ADD tab_title_en VARCHAR(255) DEFAULT NULL, ADD lead_en LONGTEXT DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL, ADD faq_en JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE service_category_city ADD title_en VARCHAR(255) DEFAULT NULL, ADD meta_title_en VARCHAR(255) DEFAULT NULL, ADD meta_desc_en LONGTEXT DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL, ADD desc2_en LONGTEXT DEFAULT NULL, ADD desc3_en LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_price ADD name_en VARCHAR(255) DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL, ADD price_text_en VARCHAR(64) DEFAULT NULL, ADD unit_en VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_team_member ADD name_en VARCHAR(128) DEFAULT NULL, ADD role_en VARCHAR(255) DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_review ADD author_en VARCHAR(128) DEFAULT NULL, ADD text_en LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_work ADD title_en VARCHAR(160) DEFAULT NULL, ADD caption_en VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_item_site_slug_en ON service_item (site_id, slug_en)');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_cat_site_slug_en ON service_category (site_id, slug_en)');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_district_city_slug_en ON service_district (city_id, slug_en)');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_page_site_slug_en ON service_page (site_id, slug_en)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_svc_item_site_slug_en ON service_item');
        $this->addSql('DROP INDEX uniq_svc_cat_site_slug_en ON service_category');
        $this->addSql('DROP INDEX uniq_svc_district_city_slug_en ON service_district');
        $this->addSql('DROP INDEX uniq_svc_page_site_slug_en ON service_page');
        $this->addSql('ALTER TABLE service_item DROP slug_en, DROP title_en, DROP lead_en, DROP desc_en, DROP desc2_en, DROP desc3_en, DROP tab_title_en, DROP name_en, DROP meta_title_en, DROP meta_desc_en, DROP faq_en');
        $this->addSql('ALTER TABLE service_category DROP slug_en, DROP title_en, DROP lead_en, DROP faq_en, DROP desc_en, DROP desc2_en, DROP desc3_en, DROP meta_title_en, DROP meta_desc_en');
        $this->addSql('ALTER TABLE service_district DROP slug_en, DROP group_en, DROP name_en, DROP title_en, DROP lead_en, DROP desc_en, DROP meta_title_en, DROP meta_desc_en, DROP sub_areas_en, DROP faq_en');
        $this->addSql('ALTER TABLE service_page DROP slug_en, DROP title_en, DROP lead_en, DROP desc_en, DROP meta_title_en, DROP meta_desc_en, DROP h1_en');
        $this->addSql('ALTER TABLE service_item_city DROP title_en, DROP meta_title_en, DROP meta_desc_en, DROP desc2_en, DROP desc3_en, DROP tab_title_en, DROP lead_en, DROP desc_en, DROP faq_en');
        $this->addSql('ALTER TABLE service_category_city DROP title_en, DROP meta_title_en, DROP meta_desc_en, DROP desc_en, DROP desc2_en, DROP desc3_en');
        $this->addSql('ALTER TABLE service_price DROP name_en, DROP desc_en, DROP price_text_en, DROP unit_en');
        $this->addSql('ALTER TABLE service_team_member DROP name_en, DROP role_en, DROP desc_en');
        $this->addSql('ALTER TABLE service_review DROP author_en, DROP text_en');
        $this->addSql('ALTER TABLE service_work DROP title_en, DROP caption_en');
    }
}
