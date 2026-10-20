<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Сторінки команди (20.10.2026, вмикає пак параметром app.team_prefix): слаг і SEO людини, додаткові поля (extra, як у
 * послуги), інші міста, де людина працює (основне — city_id), і послуги, які вона робить. Лише нові колонки й таблиці:
 * наявні записи лишаються без слагу (без сторінки) і показуються, як раніше, лише в основному місті.
 */
final class Version20261020153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_team_member: slug/meta/extra; service_team_member_city, service_team_member_item (сторінки команди)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_team_member ADD slug_uk VARCHAR(128) DEFAULT NULL, ADD slug_ru VARCHAR(128) DEFAULT NULL, ADD meta_title_uk VARCHAR(255) DEFAULT NULL, ADD meta_title_ru VARCHAR(255) DEFAULT NULL, ADD meta_desc_uk LONGTEXT DEFAULT NULL, ADD meta_desc_ru LONGTEXT DEFAULT NULL, ADD extra JSON DEFAULT NULL');
        // імена індексів — ті, що генерує Doctrine для ManyToMany (інакше schema:update бачить RENAME INDEX)
        foreach (['service_team_member_city' => ['9DA6E634', 'service_city_id', '44283033', 'service_city'], 'service_team_member_item' => ['AFE6C11E', 'service_item_id', 'DDEB00C2', 'service_item']] as $table => [$h, $col, $hc, $ref]) {
            $this->addSql("CREATE TABLE {$table} (service_team_member_id INT NOT NULL, {$col} INT NOT NULL, INDEX IDX_{$h}C1F490AF (service_team_member_id), INDEX IDX_{$h}{$hc} ({$col}), PRIMARY KEY(service_team_member_id, {$col})) DEFAULT CHARACTER SET utf8mb4");
            $this->addSql("ALTER TABLE {$table} ADD CONSTRAINT FK_{$h}C1F490AF FOREIGN KEY (service_team_member_id) REFERENCES service_team_member (id) ON DELETE CASCADE");
            $this->addSql("ALTER TABLE {$table} ADD CONSTRAINT FK_{$h}{$hc} FOREIGN KEY ({$col}) REFERENCES {$ref} (id) ON DELETE CASCADE");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_team_member_city');
        $this->addSql('DROP TABLE service_team_member_item');
        $this->addSql('ALTER TABLE service_team_member DROP slug_uk, DROP slug_ru, DROP meta_title_uk, DROP meta_title_ru, DROP meta_desc_uk, DROP meta_desc_ru, DROP extra');
    }
}
