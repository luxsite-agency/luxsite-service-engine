<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Англійські поля (22.10.2026) для сутностей, які читає контентний сайт: ServiceSite (бренд, «Про нас», юридичні тексти),
 * ServiceCity (головна), ServicePost (статті) + UNIQUE(site_id, slug_en). Адитивна, крім одного послаблення:
 * service_post.slug_uk / title_uk → NULL (англомовна стаття не має uk-слага; кілька NULL не конфліктують в
 * uniq_svc_post_site_slug_uk). Наявні рядки не змінюються, старий код працює (у сайтів uk-поля заповнені).
 */
final class Version20261022151200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Поля *_en для service_site, service_city, service_post; service_post.slug_uk/title_uk — NULL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city ADD name_nom_en VARCHAR(64) DEFAULT NULL, ADD meta_title_en VARCHAR(255) DEFAULT NULL, ADD meta_desc_en LONGTEXT DEFAULT NULL, ADD intro_title_en VARCHAR(255) DEFAULT NULL, ADD intro_text_en LONGTEXT DEFAULT NULL, ADD text2_en LONGTEXT DEFAULT NULL, ADD text3_en LONGTEXT DEFAULT NULL, ADD faq_en JSON DEFAULT NULL, ADD about_en LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_post ADD slug_en VARCHAR(160) DEFAULT NULL, ADD title_en VARCHAR(255) DEFAULT NULL, ADD lead_en LONGTEXT DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL, ADD meta_title_en VARCHAR(255) DEFAULT NULL, ADD meta_desc_en LONGTEXT DEFAULT NULL, ADD image_alt_en VARCHAR(255) DEFAULT NULL, CHANGE slug_uk slug_uk VARCHAR(160) DEFAULT NULL, CHANGE title_uk title_uk VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_post_site_slug_en ON service_post (site_id, slug_en)');
        $this->addSql('ALTER TABLE service_site ADD brand_name_en VARCHAR(128) DEFAULT NULL, ADD about_title_en VARCHAR(255) DEFAULT NULL, ADD about_en LONGTEXT DEFAULT NULL, ADD privacy_en LONGTEXT DEFAULT NULL, ADD terms_en LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // назад до NOT NULL: статті без uk-слага/заголовка отримують технічні значення, інакше ALTER впаде
        $this->addSql("UPDATE service_post SET slug_uk = CONCAT('post-', id) WHERE slug_uk IS NULL");
        $this->addSql("UPDATE service_post SET title_uk = COALESCE(title_en, CONCAT('post-', id)) WHERE title_uk IS NULL");
        $this->addSql('ALTER TABLE service_city DROP name_nom_en, DROP meta_title_en, DROP meta_desc_en, DROP intro_title_en, DROP intro_text_en, DROP text2_en, DROP text3_en, DROP faq_en, DROP about_en');
        $this->addSql('DROP INDEX uniq_svc_post_site_slug_en ON service_post');
        $this->addSql('ALTER TABLE service_post DROP slug_en, DROP title_en, DROP lead_en, DROP desc_en, DROP meta_title_en, DROP meta_desc_en, DROP image_alt_en, CHANGE slug_uk slug_uk VARCHAR(160) NOT NULL, CHANGE title_uk title_uk VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE service_site DROP brand_name_en, DROP about_title_en, DROP about_en, DROP privacy_en, DROP terms_en');
    }
}
