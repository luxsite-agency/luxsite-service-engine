<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Юридичні сторінки бренду (політика конфіденційності, умови послуг) і
 * привʼязка послуги до типу прибирання - для міні-калькулятора на сторінці.
 */
final class Version20260902090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_site: privacy/terms; service_item: calc_type_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_site ADD privacy_uk LONGTEXT DEFAULT NULL, ADD privacy_ru LONGTEXT DEFAULT NULL, ADD terms_uk LONGTEXT DEFAULT NULL, ADD terms_ru LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_item ADD calc_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE service_item ADD CONSTRAINT FK_ITEM_CALC_TYPE FOREIGN KEY (calc_type_id) REFERENCES service_calc_type (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_ITEM_CALC_TYPE ON service_item (calc_type_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_item DROP FOREIGN KEY FK_ITEM_CALC_TYPE');
        $this->addSql('DROP INDEX IDX_ITEM_CALC_TYPE ON service_item');
        $this->addSql('ALTER TABLE service_item DROP calc_type_id');
        $this->addSql('ALTER TABLE service_site DROP privacy_uk, DROP privacy_ru, DROP terms_uk, DROP terms_ru');
    }
}
