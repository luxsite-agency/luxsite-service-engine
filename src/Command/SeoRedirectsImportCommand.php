<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Repository\ServiceSiteRepository;
use App\ServiceSite\Redirect\RedirectCsv;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Великі мапи редиректів (понад RedirectCsv::WEB_MAX_ROWS) — без ліміту рядків і часу запиту; формат як в адмінці. */
#[AsCommand(name: 'app:seo:redirects:import', description: 'Імпорт правил редиректів сайту з CSV source;code;target[;city] (без ліміту рядків)')]
final class SeoRedirectsImportCommand extends Command
{
    public function __construct(private readonly RedirectCsv $csv, private readonly ServiceSiteRepository $sites)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('site', InputArgument::REQUIRED, 'Slug сайту-бренду')
            ->addArgument('file', InputArgument::REQUIRED, 'Шлях до CSV (UTF-8, роздільник «;»)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = $this->sites->findOneBy(['slug' => (string) $input->getArgument('site')]);
        if ($site === null) {
            $io->error(sprintf('Сайт «%s» не знайдено', (string) $input->getArgument('site')));

            return Command::INVALID;
        }
        $file = (string) $input->getArgument('file');
        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('Файл %s не знайдено або не читається', $file));

            return Command::FAILURE;
        }
        $report = $this->csv->import($site, $file, null);
        if ($report['errors'] !== []) {
            $io->table(['рядок', 'помилка'], array_map(static fn (array $e): array => [$e['line'], $e['message']], $report['errors']));
        }
        $io->success(sprintf('Створено: %d, замінено: %d, з помилками: %d', $report['created'], $report['updated'], \count($report['errors'])));

        return $report['errors'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
