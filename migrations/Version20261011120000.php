<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** service_city: месенджери міста (viber, telegram, whatsapp) — Viber у шапці/футері/контактах Joomla-сайтів. */
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_city: viber, telegram, whatsapp (посилання-месенджери міста)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city ADD viber VARCHAR(20) DEFAULT NULL, ADD telegram VARCHAR(64) DEFAULT NULL, ADD whatsapp VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_city DROP viber, DROP telegram, DROP whatsapp');
    }
}
