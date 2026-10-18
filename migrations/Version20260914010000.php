<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Наші роботи: власні фото з об'єктів.
 *
 * Чому не «до/після»: у наявних зйомках пар немає, усе знято в уже чистих
 * приміщеннях. Тому основне фото обовʼязкове, а «до» - необовʼязкове поле
 * на випадок, коли справжня пара зʼявиться. Робити навпаки означало б
 * завести сотню порожніх полів, які ніхто не заповнить.
 *
 * city_id порожній = робота показується в усіх містах, item_id порожній =
 * на всіх сторінках послуг.
 */
final class Version20260914010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_work: галерея власних робіт';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_work (
            id INT AUTO_INCREMENT NOT NULL,
            city_id INT DEFAULT NULL,
            item_id INT DEFAULT NULL,
            title_uk VARCHAR(160) NOT NULL,
            title_ru VARCHAR(160) DEFAULT NULL,
            caption_uk VARCHAR(255) DEFAULT NULL,
            caption_ru VARCHAR(255) DEFAULT NULL,
            image_name VARCHAR(255) DEFAULT NULL,
            image_alt VARCHAR(255) DEFAULT NULL,
            before_name VARCHAR(255) DEFAULT NULL,
            before_alt VARCHAR(255) DEFAULT NULL,
            position INT DEFAULT 0 NOT NULL,
            active TINYINT(1) DEFAULT 1 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_work_city (city_id, active, position),
            INDEX idx_work_item (item_id, active, position),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE service_work
            ADD CONSTRAINT FK_work_city FOREIGN KEY (city_id)
                REFERENCES service_city (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_work_item FOREIGN KEY (item_id)
                REFERENCES service_item (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_work');
    }
}
