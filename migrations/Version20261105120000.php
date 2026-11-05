<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Користувач кабінету компанії: необов’язковий зв’язок із партнером';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD partner_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_user_partner ON `user` (partner_id)');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D6499393F8FE FOREIGN KEY (partner_id) REFERENCES service_partner (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D6499393F8FE');
        $this->addSql('DROP INDEX idx_user_partner ON `user`');
        $this->addSql('ALTER TABLE `user` DROP partner_id');
    }
}
