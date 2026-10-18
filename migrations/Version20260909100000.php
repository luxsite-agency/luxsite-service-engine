<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Приймач content-studio: ключ upsert статей.
 * Злиття рушія (10.2026): на БД другого сайту ті самі колонки додала Version20260908212123 -
 * крок пропускається, якщо колонка вже є.
 */
final class Version20260909100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_post: studio_group_id + studio_synced_at (приймач content-studio)';
    }

    public function up(Schema $schema): void
    {
        // Без skipIf: SkipMigration не записує версію в doctrine_migration_versions, і крок повторювався б на
        // кожному деплої (doctor: «є невиконані»). Порожнє up() записується як виконане (Doctrine лише попередить про 0 SQL).
        if ($schema->getTable('service_post')->hasColumn('studio_group_id')) {
            $this->write('пропуск: ' . 'service_post.studio_group_id уже є (Version20260908212123)');
        
            return;
        }

        // Приймач content-studio (satellit): ключ upsert (site, studio_group_id) + час синку
        $this->addSql('ALTER TABLE service_post ADD studio_group_id VARCHAR(36) DEFAULT NULL, ADD studio_synced_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_svc_post_site_studio_group ON service_post (site_id, studio_group_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_svc_post_site_studio_group ON service_post');
        $this->addSql('ALTER TABLE service_post DROP studio_group_id, DROP studio_synced_at');
    }
}
