<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CRM як конектор сайту (13.10.2026): поля заявки pipedrive_* стають нейтральними crm_*.
 *
 * Перейменування, а не нові колонки: на сайтах з Pipedrive у pipedrive_lead_id лежать id лідів, за якими app:crm:sync
 * знає, що вже відправлено, - дані мають пережити міграцію. CHANGE (а не RENAME COLUMN) - працює однаково на
 * MySQL 5.7/8 і MariaDB; заодно crm_external_id ширшає до 64 (id угоди Bitrix24, uuid Pipedrive - 36).
 * crm_system - у яку CRM пішла заявка; наявним відправленим ставимо pipedrive (іншої CRM до цього не було).
 */
final class Version20261013120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_lead: pipedrive_* -> crm_external_id/crm_synced_at/crm_error (+ crm_system), індекс idx_svc_lead_crm';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_svc_lead_pipedrive ON service_lead');
        $this->addSql('ALTER TABLE service_lead
            CHANGE pipedrive_lead_id crm_external_id VARCHAR(64) DEFAULT NULL,
            CHANGE pipedrive_synced_at crm_synced_at DATETIME DEFAULT NULL,
            CHANGE pipedrive_error crm_error VARCHAR(500) DEFAULT NULL,
            ADD crm_system VARCHAR(32) DEFAULT NULL');
        $this->addSql("UPDATE service_lead SET crm_system = 'pipedrive' WHERE crm_external_id IS NOT NULL");
        $this->addSql('CREATE INDEX idx_svc_lead_crm ON service_lead (crm_external_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        // id угод Bitrix24 (до 64 символів) у VARCHAR(36) не влізуть - відкат лише поки інших CRM, крім Pipedrive, не було
        $this->addSql('DROP INDEX idx_svc_lead_crm ON service_lead');
        $this->addSql('ALTER TABLE service_lead
            DROP crm_system,
            CHANGE crm_external_id pipedrive_lead_id VARCHAR(36) DEFAULT NULL,
            CHANGE crm_synced_at pipedrive_synced_at DATETIME DEFAULT NULL,
            CHANGE crm_error pipedrive_error VARCHAR(500) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_svc_lead_pipedrive ON service_lead (pipedrive_lead_id, created_at)');
    }
}
