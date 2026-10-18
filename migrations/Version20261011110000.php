<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** service_category.landing — чи є в категорії посадкова сторінка (рішення власника 11.10.2026); типово так, як було. */
final class Version20261011110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_category: landing (посадкова сторінка категорії, типово true)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_category ADD landing TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_category DROP landing');
    }
}
