<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Послуга по містах (власник 14.10.2026): де не надається і де не показуємо ціну — дві таблиці зв'язку ServiceItem ↔ ServiceCity. */
final class Version20261014100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_item_hidden_city, service_item_price_hidden_city (послуга/ціни не в усіх містах)';
    }

    public function up(Schema $schema): void
    {
        // імена індексів — ті, що генерує Doctrine для ManyToMany (інакше schema:update бачить RENAME INDEX)
        foreach (['service_item_hidden_city' => 'E34D60BF', 'service_item_price_hidden_city' => 'E51FFDB8'] as $table => $h) {
            $this->addSql("CREATE TABLE {$table} (service_item_id INT NOT NULL, service_city_id INT NOT NULL, INDEX IDX_{$h}DDEB00C2 (service_item_id), INDEX IDX_{$h}44283033 (service_city_id), PRIMARY KEY(service_item_id, service_city_id)) DEFAULT CHARACTER SET utf8mb4");
            $this->addSql("ALTER TABLE {$table} ADD CONSTRAINT FK_{$h}DDEB00C2 FOREIGN KEY (service_item_id) REFERENCES service_item (id) ON DELETE CASCADE");
            $this->addSql("ALTER TABLE {$table} ADD CONSTRAINT FK_{$h}44283033 FOREIGN KEY (service_city_id) REFERENCES service_city (id) ON DELETE CASCADE");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE service_item_hidden_city');
        $this->addSql('DROP TABLE service_item_price_hidden_city');
    }
}
