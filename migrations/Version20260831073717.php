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
final class Version20260831073717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ServiceReview: user FK + avatarUrl (Google-відгуки, патерн city-sites)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE service_review ADD avatar_url VARCHAR(512) DEFAULT NULL, ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_review ADD CONSTRAINT FK_478484F0A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_478484F0A76ED395 ON service_review (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_review_user_city ON service_review (user_id, city_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE service_review DROP FOREIGN KEY FK_478484F0A76ED395');
        $this->addSql('DROP INDEX IDX_478484F0A76ED395 ON service_review');
        $this->addSql('DROP INDEX uniq_svc_review_user_city ON service_review');
        $this->addSql('ALTER TABLE service_review DROP avatar_url, DROP user_id');
    }
}
