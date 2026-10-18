<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Джерело заявки: UTM-мітки, gclid, реферер і сторінка входу.
 * Без цього в CRM усі заявки виглядають однаково безрідними й окупність
 * реклами порахувати нічим.
 *
 * Злиття рушія (10.2026): на БД сайту з гілки nested ці ж колонки додала Version20260907120000,
 * тож тут крок пропускається, якщо колонка вже є (skipIf позначає версію виконаною).
 */
final class Version20260907100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_lead: джерело переходу (utm, gclid, referrer, landing)';
    }

    public function up(Schema $schema): void
    {
        // Без skipIf: SkipMigration не записує версію в doctrine_migration_versions, і крок повторювався б на
        // кожному деплої (doctor: «є невиконані»). Порожнє up() записується як виконане (Doctrine лише попередить про 0 SQL).
        if ($schema->getTable('service_lead')->hasColumn('utm_source')) {
            $this->write('пропуск: ' . 'service_lead.utm_* уже є (Version20260907120000)');
        
            return;
        }

        $this->addSql('ALTER TABLE service_lead
            ADD utm_source VARCHAR(128) DEFAULT NULL,
            ADD utm_medium VARCHAR(128) DEFAULT NULL,
            ADD utm_campaign VARCHAR(191) DEFAULT NULL,
            ADD utm_content VARCHAR(191) DEFAULT NULL,
            ADD utm_term VARCHAR(191) DEFAULT NULL,
            ADD click_id VARCHAR(191) DEFAULT NULL,
            ADD referrer VARCHAR(512) DEFAULT NULL,
            ADD landing_url VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_lead
            DROP utm_source, DROP utm_medium, DROP utm_campaign, DROP utm_content,
            DROP utm_term, DROP click_id, DROP referrer, DROP landing_url');
    }
}
