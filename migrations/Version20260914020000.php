<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Приводить часові колонки service_work до трейта Timestamps.
 *
 * У попередній міграції я оголосив їх як datetime_immutable і created_at
 * NOT NULL, а трейт Timestamps (тепер App\Doctrine\TimestampsTrait) мапить обидві як
 * nullable datetime із Gedmo. Розбіжність не ламає запис, але залишає
 * назавжди брудний doctrine:schema:validate, а на ньому ми ловимо справжні
 * проблеми - тому вирівнюю зараз, поки таблиця порожня.
 */
final class Version20260914020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_work: часові колонки під трейт Timestamps';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_work
            CHANGE created_at created_at DATETIME DEFAULT NULL,
            CHANGE updated_at updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_work
            CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
