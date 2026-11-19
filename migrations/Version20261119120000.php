<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Дипломи й сертифікати лікаря (19.11.2026): service_team_image — скани в картці співробітника, на сторінці лікаря
 * блоком «Дипломи та сертифікати». Адитивно: реліз без цієї міграції працює з новою схемою.
 */
final class Version20261119120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_team_image (документи лікаря)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_team_image (id INT AUTO_INCREMENT NOT NULL, image_name VARCHAR(255) DEFAULT NULL, caption_uk VARCHAR(255) DEFAULT NULL, caption_ru VARCHAR(255) DEFAULT NULL, note_uk VARCHAR(255) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, member_id INT NOT NULL, INDEX IDX_SVC_TEAM_IMAGE_MEMBER_FK (member_id), INDEX idx_svc_team_image_member (member_id, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_team_image ADD CONSTRAINT FK_SVC_TEAM_IMAGE_MEMBER FOREIGN KEY (member_id) REFERENCES service_team_member (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_team_image DROP FOREIGN KEY FK_SVC_TEAM_IMAGE_MEMBER');
        $this->addSql('DROP TABLE service_team_image');
    }
}
