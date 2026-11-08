<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Захист форм від спаму (08.11.2026, app.lead_spam): service_lead.spam_reason — причина, з якої LeadSpamGuard визнав
 * заявку спамом (null — звичайна заявка). Адитивно.
 */
final class Version20261108100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_lead.spam_reason';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead ADD spam_reason VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead DROP spam_reason');
    }
}
