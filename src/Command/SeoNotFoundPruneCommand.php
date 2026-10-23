<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\ServiceSite\Redirect\NotFoundLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Журнал 404 (service_not_found) за лімітами app.seo_not_found_days / _limit на сайт; у cron — щоночі (docs/site-ops.md). */
#[AsCommand(name: 'app:seo:not-found:prune', description: 'Прибрати журнал 404: старіші за app.seo_not_found_days і понад app.seo_not_found_limit рядків на сайт (--all — усе)')]
final class SeoNotFoundPruneCommand extends Command
{
    public function __construct(private readonly NotFoundLog $log)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Очистити журнал повністю (усі сайти)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success(sprintf('Видалено рядків журналу 404: %d', $input->getOption('all') ? $this->log->clear() : $this->log->prune()));

        return Command::SUCCESS;
    }
}
