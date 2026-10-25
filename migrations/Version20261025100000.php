<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Прев'ю зображень статей у списках контентного сайту (25.10.2026): service_site.list_thumbs — off (типово) | home | lists | all,
 * перемикач в адмінці → бренд. Адитивна: наявні бренди отримують off, сторінки не змінюються.
 */
final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_site.list_thumbs: прев\'ю зображень статей у списках (off за замовчуванням)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_site ADD list_thumbs VARCHAR(8) DEFAULT \'off\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_site DROP list_thumbs');
    }
}
