<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Бренди користувача й токена (22.10.2026): user.sites і admin_api_token.sites — slug-и брендів для API, MCP і студії.
 * Адитивна: nullable JSON, NULL = усі бренди (так працюють наявні користувачі й токени).
 */
final class Version20261022154127 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.sites, admin_api_token.sites: бренди користувача й токена (NULL = усі)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_api_token ADD sites JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD sites JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_api_token DROP sites');
        $this->addSql('ALTER TABLE `user` DROP sites');
    }
}
