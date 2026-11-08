<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Мова de (08.11.2026): колонки *_de поруч із *_uk/*_ru в усіх локалізованих сутностях (сайт, місто, каталог, сторінки,
 * команда, райони, відгуки, прайс, блог, роботи, калькулятор) + унікальні індекси (site_id|city_id, slug_de), як у slug_ru.
 * Адитивна: лише ADD COLUMN … NULL; дані й колонки наявних сайтів (uk/ru/en) не чіпаються, без de в app.locales
 * нові колонки лишаються порожніми й ні на що не впливають.
 */
final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Мова de: колонки *_de в локалізованих сутностях і унікальні індекси slug_de';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_author ADD role_de VARCHAR(255) DEFAULT NULL, ADD bio_de LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_calc_factor ADD name_de VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_calc_option ADD name_de VARCHAR(128) DEFAULT NULL, ADD unit_de VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_calc_type ADD name_de VARCHAR(128) DEFAULT NULL, ADD desc_de VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_category ADD slug_de VARCHAR(160) DEFAULT NULL, ADD title_de VARCHAR(255) DEFAULT NULL, ADD lead_de LONGTEXT DEFAULT NULL, ADD faq_de JSON DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL, ADD desc2_de LONGTEXT DEFAULT NULL, ADD desc3_de LONGTEXT DEFAULT NULL, ADD meta_title_de VARCHAR(255) DEFAULT NULL, ADD meta_desc_de LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_cat_site_slug_de ON service_category (site_id, slug_de)');
        $this->addSql('ALTER TABLE service_category_city ADD title_de VARCHAR(255) DEFAULT NULL, ADD meta_title_de VARCHAR(255) DEFAULT NULL, ADD meta_desc_de LONGTEXT DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL, ADD desc2_de LONGTEXT DEFAULT NULL, ADD desc3_de LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_city ADD address_de VARCHAR(255) DEFAULT NULL, ADD about_de LONGTEXT DEFAULT NULL, ADD about_where_de LONGTEXT DEFAULT NULL, ADD name_nom_de VARCHAR(64) DEFAULT NULL, ADD name_gen_de VARCHAR(64) DEFAULT NULL, ADD name_dat_de VARCHAR(64) DEFAULT NULL, ADD name_loc_de VARCHAR(64) DEFAULT NULL, ADD meta_title_de VARCHAR(255) DEFAULT NULL, ADD meta_desc_de LONGTEXT DEFAULT NULL, ADD intro_title_de VARCHAR(255) DEFAULT NULL, ADD intro_text_de LONGTEXT DEFAULT NULL, ADD text2_de LONGTEXT DEFAULT NULL, ADD text3_de LONGTEXT DEFAULT NULL, ADD faq_de JSON DEFAULT NULL, ADD districts_intro_de LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_district ADD slug_de VARCHAR(64) DEFAULT NULL, ADD group_de VARCHAR(128) DEFAULT NULL, ADD name_de VARCHAR(128) DEFAULT NULL, ADD title_de VARCHAR(255) DEFAULT NULL, ADD lead_de LONGTEXT DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL, ADD meta_title_de VARCHAR(255) DEFAULT NULL, ADD meta_desc_de LONGTEXT DEFAULT NULL, ADD sub_areas_de LONGTEXT DEFAULT NULL, ADD faq_de JSON DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_district_city_slug_de ON service_district (city_id, slug_de)');
        $this->addSql('ALTER TABLE service_item ADD slug_de VARCHAR(160) DEFAULT NULL, ADD title_de VARCHAR(255) DEFAULT NULL, ADD lead_de LONGTEXT DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL, ADD desc2_de LONGTEXT DEFAULT NULL, ADD desc3_de LONGTEXT DEFAULT NULL, ADD tab_title_de VARCHAR(255) DEFAULT NULL, ADD name_de VARCHAR(255) DEFAULT NULL, ADD meta_title_de VARCHAR(255) DEFAULT NULL, ADD meta_desc_de LONGTEXT DEFAULT NULL, ADD faq_de JSON DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_item_site_slug_de ON service_item (site_id, slug_de)');
        $this->addSql('ALTER TABLE service_item_city ADD title_de VARCHAR(255) DEFAULT NULL, ADD meta_title_de VARCHAR(255) DEFAULT NULL, ADD meta_desc_de LONGTEXT DEFAULT NULL, ADD desc2_de LONGTEXT DEFAULT NULL, ADD desc3_de LONGTEXT DEFAULT NULL, ADD tab_title_de VARCHAR(255) DEFAULT NULL, ADD lead_de LONGTEXT DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL, ADD faq_de JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE service_page ADD slug_de VARCHAR(160) DEFAULT NULL, ADD title_de VARCHAR(255) DEFAULT NULL, ADD lead_de LONGTEXT DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL, ADD meta_title_de VARCHAR(255) DEFAULT NULL, ADD meta_desc_de LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_page_site_slug_de ON service_page (site_id, slug_de)');
        $this->addSql('ALTER TABLE service_page_image ADD caption_de VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_post ADD slug_de VARCHAR(160) DEFAULT NULL, ADD title_de VARCHAR(255) DEFAULT NULL, ADD lead_de LONGTEXT DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL, ADD meta_title_de VARCHAR(255) DEFAULT NULL, ADD meta_desc_de LONGTEXT DEFAULT NULL, ADD image_alt_de VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_post_site_slug_de ON service_post (site_id, slug_de)');
        $this->addSql('ALTER TABLE service_post_category ADD slug_de VARCHAR(160) DEFAULT NULL, ADD title_de VARCHAR(255) DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL, ADD meta_title_de VARCHAR(255) DEFAULT NULL, ADD meta_desc_de LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_post_cat_site_slug_de ON service_post_category (site_id, slug_de)');
        $this->addSql('ALTER TABLE service_post_tag ADD slug_de VARCHAR(160) DEFAULT NULL, ADD title_de VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_post_tag_site_slug_de ON service_post_tag (site_id, slug_de)');
        $this->addSql('ALTER TABLE service_price ADD name_de VARCHAR(255) DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL, ADD price_text_de VARCHAR(64) DEFAULT NULL, ADD unit_de VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_review ADD author_de VARCHAR(128) DEFAULT NULL, ADD text_de LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_site ADD brand_name_de VARCHAR(128) DEFAULT NULL, ADD about_title_de VARCHAR(255) DEFAULT NULL, ADD about_de LONGTEXT DEFAULT NULL, ADD privacy_de LONGTEXT DEFAULT NULL, ADD terms_de LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_team_member ADD name_de VARCHAR(128) DEFAULT NULL, ADD role_de VARCHAR(255) DEFAULT NULL, ADD desc_de LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_work ADD title_de VARCHAR(160) DEFAULT NULL, ADD caption_de VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_author DROP role_de, DROP bio_de');
        $this->addSql('ALTER TABLE service_calc_factor DROP name_de');
        $this->addSql('ALTER TABLE service_calc_option DROP name_de, DROP unit_de');
        $this->addSql('ALTER TABLE service_calc_type DROP name_de, DROP desc_de');
        $this->addSql('DROP INDEX uniq_svc_cat_site_slug_de ON service_category');
        $this->addSql('ALTER TABLE service_category DROP slug_de, DROP title_de, DROP lead_de, DROP faq_de, DROP desc_de, DROP desc2_de, DROP desc3_de, DROP meta_title_de, DROP meta_desc_de');
        $this->addSql('ALTER TABLE service_category_city DROP title_de, DROP meta_title_de, DROP meta_desc_de, DROP desc_de, DROP desc2_de, DROP desc3_de');
        $this->addSql('ALTER TABLE service_city DROP address_de, DROP about_de, DROP about_where_de, DROP name_nom_de, DROP name_gen_de, DROP name_dat_de, DROP name_loc_de, DROP meta_title_de, DROP meta_desc_de, DROP intro_title_de, DROP intro_text_de, DROP text2_de, DROP text3_de, DROP faq_de, DROP districts_intro_de');
        $this->addSql('DROP INDEX uniq_svc_district_city_slug_de ON service_district');
        $this->addSql('ALTER TABLE service_district DROP slug_de, DROP group_de, DROP name_de, DROP title_de, DROP lead_de, DROP desc_de, DROP meta_title_de, DROP meta_desc_de, DROP sub_areas_de, DROP faq_de');
        $this->addSql('DROP INDEX uniq_svc_item_site_slug_de ON service_item');
        $this->addSql('ALTER TABLE service_item DROP slug_de, DROP title_de, DROP lead_de, DROP desc_de, DROP desc2_de, DROP desc3_de, DROP tab_title_de, DROP name_de, DROP meta_title_de, DROP meta_desc_de, DROP faq_de');
        $this->addSql('ALTER TABLE service_item_city DROP title_de, DROP meta_title_de, DROP meta_desc_de, DROP desc2_de, DROP desc3_de, DROP tab_title_de, DROP lead_de, DROP desc_de, DROP faq_de');
        $this->addSql('DROP INDEX uniq_svc_page_site_slug_de ON service_page');
        $this->addSql('ALTER TABLE service_page DROP slug_de, DROP title_de, DROP lead_de, DROP desc_de, DROP meta_title_de, DROP meta_desc_de');
        $this->addSql('ALTER TABLE service_page_image DROP caption_de');
        $this->addSql('DROP INDEX uniq_svc_post_site_slug_de ON service_post');
        $this->addSql('ALTER TABLE service_post DROP slug_de, DROP title_de, DROP lead_de, DROP desc_de, DROP meta_title_de, DROP meta_desc_de, DROP image_alt_de');
        $this->addSql('DROP INDEX uniq_svc_post_cat_site_slug_de ON service_post_category');
        $this->addSql('ALTER TABLE service_post_category DROP slug_de, DROP title_de, DROP desc_de, DROP meta_title_de, DROP meta_desc_de');
        $this->addSql('DROP INDEX uniq_svc_post_tag_site_slug_de ON service_post_tag');
        $this->addSql('ALTER TABLE service_post_tag DROP slug_de, DROP title_de');
        $this->addSql('ALTER TABLE service_price DROP name_de, DROP desc_de, DROP price_text_de, DROP unit_de');
        $this->addSql('ALTER TABLE service_review DROP author_de, DROP text_de');
        $this->addSql('ALTER TABLE service_site DROP brand_name_de, DROP about_title_de, DROP about_de, DROP privacy_de, DROP terms_de');
        $this->addSql('ALTER TABLE service_team_member DROP name_de, DROP role_de, DROP desc_de');
        $this->addSql('ALTER TABLE service_work DROP title_de, DROP caption_de');
    }
}
