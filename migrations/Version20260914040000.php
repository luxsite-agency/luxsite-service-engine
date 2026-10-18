<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * service_item_city: часові колонки.
 *
 * Потрібні для lastmod у карті сайту. Зараз із 358 адрес дату зміни мають
 * лише 14 (блог), тому Google не бачить, що сторінка послуги оновилась -
 * а тексти під місто живуть саме тут.
 *
 * Колонки nullable datetime під трейт App\Doctrine\TimestampsTrait
 * (не datetime_immutable - на цьому вже обпікались двічі).
 */
final class Version20260914040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_item_city: created_at, updated_at під lastmod';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_item_city
            ADD created_at DATETIME DEFAULT NULL,
            ADD updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_item_city
            DROP created_at,
            DROP updated_at');
    }
}
