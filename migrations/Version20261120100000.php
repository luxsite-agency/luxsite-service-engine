<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Файли підтвердження Google і BingSiteAuth.xml прибрано (20.11.2026, підтвердження через DNS TXT): рядки налаштувань
 * seo.google_verification_files і seo.bing_site_auth більше нічого не роблять і в реєстрі ключів їх нема. Лише дані.
 */
final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'service_site_setting: прибрати рядки seo.google_verification_files і seo.bing_site_auth';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM service_site_setting WHERE code IN ('seo.google_verification_files', 'seo.bing_site_auth')");
    }

    public function down(Schema $schema): void
    {
        // відновлювати нічого: ключів нема в коді, а значення (імена файлів) без файлового маршруту не діють;
        // схема не змінювалась, тож відкат релізу з цією міграцією безпечний
        $this->write('Рядки seo.google_verification_files / seo.bing_site_auth не відновлюються (ключі прибрано з коду).');
    }
}
