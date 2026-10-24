<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Теги статей (24.10.2026, контентний сайт): service_post_tag (слаг і назва uk/ru/en, унікальний слаг у межах сайту й мови)
 * і зв'язок service_post_tags (ON DELETE CASCADE з обох боків). Адитивна: нові таблиці, наявні рядки не змінюються.
 */
final class Version20261024200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Теги статей: service_post_tag і service_post_tags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_post_tag (id INT AUTO_INCREMENT NOT NULL, slug_uk VARCHAR(160) DEFAULT NULL, slug_ru VARCHAR(160) DEFAULT NULL, slug_en VARCHAR(160) DEFAULT NULL, title_uk VARCHAR(255) DEFAULT NULL, title_ru VARCHAR(255) DEFAULT NULL, title_en VARCHAR(255) DEFAULT NULL, active TINYINT DEFAULT 1 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, site_id INT NOT NULL, INDEX IDX_A3B861CDF6BD1646 (site_id), UNIQUE INDEX uniq_svc_post_tag_site_slug_uk (site_id, slug_uk), UNIQUE INDEX uniq_svc_post_tag_site_slug_ru (site_id, slug_ru), UNIQUE INDEX uniq_svc_post_tag_site_slug_en (site_id, slug_en), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE service_post_tags (post_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_FE78C9674B89032C (post_id), INDEX IDX_FE78C967BAD26311 (tag_id), PRIMARY KEY (post_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_post_tag ADD CONSTRAINT FK_A3B861CDF6BD1646 FOREIGN KEY (site_id) REFERENCES service_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_post_tags ADD CONSTRAINT FK_FE78C9674B89032C FOREIGN KEY (post_id) REFERENCES service_post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_post_tags ADD CONSTRAINT FK_FE78C967BAD26311 FOREIGN KEY (tag_id) REFERENCES service_post_tag (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_post_tags DROP FOREIGN KEY FK_FE78C9674B89032C');
        $this->addSql('ALTER TABLE service_post_tags DROP FOREIGN KEY FK_FE78C967BAD26311');
        $this->addSql('ALTER TABLE service_post_tag DROP FOREIGN KEY FK_A3B861CDF6BD1646');
        $this->addSql('DROP TABLE service_post_tags');
        $this->addSql('DROP TABLE service_post_tag');
    }
}
