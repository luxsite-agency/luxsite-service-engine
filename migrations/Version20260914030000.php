<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Прибирає останні розбіжності, через які doctrine:schema:validate стабільно
 * червонів, а отже нічого не ловив.
 *
 * 1. service_calc_type_city: created_at/updated_at я оголосив як
 *    datetime_immutable, а трейт Timestamps мапить nullable datetime -
 *    та сама помилка, що вже виправлена для service_work.
 * 2. service_lead.pipedrive_synced_at: коментар (DC2Type:...) DBAL 4 більше
 *    не використовує, тип береться з мапінгу. Знімаємо, щоб діф був чистий.
 */
final class Version20260914030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Вирівнювання часових колонок під мапінг (calc_type_city, lead)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_calc_type_city
            CHANGE created_at created_at DATETIME DEFAULT NULL,
            CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE service_lead
            CHANGE pipedrive_synced_at pipedrive_synced_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_calc_type_city
            CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE service_lead
            CHANGE pipedrive_synced_at pipedrive_synced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
