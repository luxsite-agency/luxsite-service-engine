<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Персональні API-токени менеджерів (15.10.2026, модель shop-engine): лише хеш, скоупи, строк, відкликання, слід використання. */
final class Version20261015100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'admin_api_token: персональні API-токени менеджерів';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_api_token (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, name VARCHAR(100) NOT NULL, token_hash CHAR(64) NOT NULL, prefix VARCHAR(16) NOT NULL, scopes JSON NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, revoked_by_id INT DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, last_used_ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_admin_api_token_hash (token_hash), INDEX idx_admin_api_token_user (user_id, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE admin_api_token ADD CONSTRAINT FK_7A2A8A1AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_api_token');
    }
}
