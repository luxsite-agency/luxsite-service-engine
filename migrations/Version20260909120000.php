<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Обкладинка поста: alt по мовах (ru окремо; uk у image_alt). Пише приймач content-studio. */
final class Version20260909120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_post.image_alt_ru';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_post ADD image_alt_ru VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_post DROP image_alt_ru');
    }
}
