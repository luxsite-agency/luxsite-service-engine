<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Питання-відповіді на сторінці локації: свої під район, з FAQPage-розміткою. */
final class Version20260902140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_district: faq_uk, faq_ru';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_district ADD faq_uk JSON DEFAULT NULL, ADD faq_ru JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_district DROP faq_uk, DROP faq_ru');
    }
}
