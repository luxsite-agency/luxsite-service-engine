<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Прайс стає єдиним джерелом цін: і для таблиць на сторінках послуг, і для
 * опцій калькулятора. Досі це були дві незалежні таблиці, які розходились,
 * та ще й опції калькулятора не знали про місто - хоча в партнерському
 * прайсі, наприклад, дивани в Дніпрі дешевші за київські.
 *
 * item_id стає необовʼязковим: позиція на кшталт «Озонування» живе в
 * калькуляторі й не належить жодній окремій послузі.
 *
 * Міграцію навмисно винесено окремим кроком перед кодом - Doctrine читає всі
 * мапнуті колонки одразу, тож код, який їде раніше за колонки, кладе сайт.
 */
final class Version20260913230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_price: поля калькулятора і необовʼязкова послуга';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_price
            ADD in_calculator TINYINT(1) DEFAULT 0 NOT NULL,
            ADD per_unit TINYINT(1) DEFAULT 0 NOT NULL,
            ADD unit_uk VARCHAR(32) DEFAULT NULL,
            ADD unit_ru VARCHAR(32) DEFAULT NULL,
            ADD max_qty INT DEFAULT 30 NOT NULL');

        $this->addSql('ALTER TABLE service_price MODIFY item_id INT DEFAULT NULL');

        $this->addSql('CREATE INDEX idx_svc_price_calc ON service_price (city_id, in_calculator, active, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_svc_price_calc ON service_price');
        $this->addSql('DELETE FROM service_price WHERE item_id IS NULL');
        $this->addSql('ALTER TABLE service_price MODIFY item_id INT NOT NULL');
        $this->addSql('ALTER TABLE service_price
            DROP in_calculator, DROP per_unit, DROP unit_uk, DROP unit_ru, DROP max_qty');
    }
}
