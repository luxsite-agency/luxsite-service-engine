<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Узагальнений імпорт Joomla (другий сайт з Seblod, 13.10.2026): коротка назва послуги, додаткові поля записів
 * (extra JSON на послузі, статті й розділі — набір полів задає пак, app.custom_fields; тип авто: тоннаж, кузов,
 * габарити, об'єм), лід і FAQ категорії, «звідки» автора відгуку.
 * Лише нові nullable-колонки — на наявних сайтах нічого не змінюється.
 */
final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_item: name, extra; service_post: extra; service_category: lead, faq, extra; service_review: location';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_category ADD lead_uk LONGTEXT DEFAULT NULL, ADD lead_ru LONGTEXT DEFAULT NULL, ADD faq_uk JSON DEFAULT NULL, ADD faq_ru JSON DEFAULT NULL, ADD extra JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE service_item ADD name_uk VARCHAR(255) DEFAULT NULL, ADD name_ru VARCHAR(255) DEFAULT NULL, ADD extra JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE service_post ADD extra JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE service_review ADD location VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_category DROP lead_uk, DROP lead_ru, DROP faq_uk, DROP faq_ru, DROP extra');
        $this->addSql('ALTER TABLE service_item DROP name_uk, DROP name_ru, DROP extra');
        $this->addSql('ALTER TABLE service_post DROP extra');
        $this->addSql('ALTER TABLE service_review DROP location');
    }
}
