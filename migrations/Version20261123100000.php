<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ServicePage: англійські поля (slug, title, lead, desc, meta title/desc) для англомовних брендів. Порожні = uk-значення.
 */
final class Version20261123100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_page: slug_en, title_en, lead_en, desc_en, meta_title_en, meta_desc_en';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_page ADD slug_en VARCHAR(160) DEFAULT NULL, ADD title_en VARCHAR(255) DEFAULT NULL, ADD lead_en LONGTEXT DEFAULT NULL, ADD desc_en LONGTEXT DEFAULT NULL, ADD meta_title_en VARCHAR(255) DEFAULT NULL, ADD meta_desc_en LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_page_site_slug_en ON service_page (site_id, slug_en)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_svc_page_site_slug_en ON service_page');
        $this->addSql('ALTER TABLE service_page DROP slug_en, DROP title_en, DROP lead_en, DROP desc_en, DROP meta_title_en, DROP meta_desc_en');
    }
}
