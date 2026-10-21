<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Settings\SecretBox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Новий ключ шифрування секретів налаштувань сайту: вставити в shared/.env.local як SETTINGS_SECRET_KEY (600, не в git,
 * чат чи доки). Зазвичай не потрібно: bin/site deploy дописує ключ сам. Ключ змінено — збережені секрети треба ввести знову.
 */
#[AsCommand(name: 'app:secrets:keygen', description: 'Згенерувати SETTINGS_SECRET_KEY для .env.local')]
final class SecretsKeygenCommand
{
    public function __invoke(OutputInterface $output): int
    {
        $output->writeln('SETTINGS_SECRET_KEY=' . SecretBox::generateKey());

        return Command::SUCCESS;
    }
}
