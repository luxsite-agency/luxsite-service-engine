<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Сторінки app.pages (02.11.2026): H1, шаблон (page|hub|sitemap|form:<ключ>), noindex, «у карті сайту», послуги хаба
 * (service_page_item); noindex послуги й категорії. Адитивно: попередній реліз на новій схемі працює, типові значення
 * колонок дають поведінку до міграції.
 */
final class Version20261102100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_page: h1, template, noindex, in_sitemap; service_page_item; service_item/service_category noindex';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_page_item (service_page_id INT NOT NULL, service_item_id INT NOT NULL, INDEX IDX_C04E038EC3C23178 (service_page_id), INDEX IDX_C04E038EDDEB00C2 (service_item_id), PRIMARY KEY (service_page_id, service_item_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE service_page_item ADD CONSTRAINT FK_C04E038EC3C23178 FOREIGN KEY (service_page_id) REFERENCES service_page (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_page_item ADD CONSTRAINT FK_C04E038EDDEB00C2 FOREIGN KEY (service_item_id) REFERENCES service_item (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_category ADD noindex TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE service_item ADD noindex TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE service_page ADD h1_uk VARCHAR(255) DEFAULT NULL, ADD h1_ru VARCHAR(255) DEFAULT NULL, ADD template VARCHAR(64) DEFAULT \'page\' NOT NULL, ADD noindex TINYINT DEFAULT 0 NOT NULL, ADD in_sitemap TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_page_item DROP FOREIGN KEY FK_C04E038EC3C23178');
        $this->addSql('ALTER TABLE service_page_item DROP FOREIGN KEY FK_C04E038EDDEB00C2');
        $this->addSql('DROP TABLE service_page_item');
        $this->addSql('ALTER TABLE service_category DROP noindex');
        $this->addSql('ALTER TABLE service_item DROP noindex');
        $this->addSql('ALTER TABLE service_page DROP h1_uk, DROP h1_ru, DROP template, DROP noindex, DROP in_sitemap');
    }
}
