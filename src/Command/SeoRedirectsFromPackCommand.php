<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Repository\ServiceSiteRepository;
use App\ServiceSite\Redirect\PackRedirects;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Те саме, що дія «Перенести з паку» в адмінці (PackRedirects), для сервера й скриптів деплою. */
#[AsCommand(name: 'app:seo:redirects:from-pack', description: 'Перенести app.legacy_redirects і app.gone_prefixes паку в БД як правила kind=pack (ідемпотентно)')]
final class SeoRedirectsFromPackCommand extends Command
{
    public function __construct(private readonly PackRedirects $pack, private readonly ServiceSiteRepository $sites)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site', InputArgument::REQUIRED, 'Slug сайту-бренду');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = $this->sites->findOneBy(['slug' => (string) $input->getArgument('site')]);
        if ($site === null) {
            $io->error(sprintf('Сайт «%s» не знайдено', (string) $input->getArgument('site')));

            return Command::INVALID;
        }
        $report = $this->pack->import($site);
        foreach ($report['skipped'] as $line) {
            $io->writeln('пропущено: ' . $line);
        }
        foreach ($report['errors'] as $line) {
            $io->writeln('<error>помилка: ' . $line . '</error>');
        }
        $io->success(sprintf('Створено: %d, оновлено: %d, пропущено: %d, з помилками: %d', $report['created'], $report['updated'], \count($report['skipped']), \count($report['errors'])));

        return $report['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
