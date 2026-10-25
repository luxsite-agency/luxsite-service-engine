<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\ServiceSite\Html\CommentStripper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

/**
 * Статика теми паку в релізі (public/themes/<key>/*.css|*.js — nginx віддає з ?v=): коментарі розробника геть тією ж логікою,
 * що й у HTML-відповідях (CommentStripper). Викликає bin/site.d/_remote.sh deploy після composer install; оригінали в паку
 * не змінюються — лише копія в теці релізу. JS, у якому розбір непевний, лишається як є (краще коментар, ніж зламаний файл).
 */
#[AsCommand(name: 'app:html:strip-static', description: 'Прибрати коментарі з *.css і *.js у теці (статика теми в релізі): app:html:strip-static public/themes')]
final class HtmlStripStaticCommand extends Command
{
    public function __construct(private readonly CommentStripper $stripper)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dir', InputArgument::REQUIRED, 'Тека зі статикою (рекурсивно), напр. public/themes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Лише показати, що змінилося б');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = (string) $input->getArgument('dir');
        if (!is_dir($dir)) {
            $io->error(sprintf('Теки %s нема', $dir));

            return Command::FAILURE;
        }
        $dry = (bool) $input->getOption('dry-run');
        $files = 0;
        $changed = 0;
        $saved = 0;
        $skipped = [];
        $failed = [];
        foreach ((new Finder())->files()->in($dir)->name(['*.css', '*.js'])->sortByName() as $file) {
            ++$files;
            $path = $file->getPathname();
            $src = (string) file_get_contents($path);
            if ($file->getExtension() === 'css') {
                $clean = $this->stripper->css($src);
            } else {
                $clean = $this->stripper->js($src);
                if ($clean === null) {
                    $skipped[] = $path;
                    continue;
                }
            }
            if ($clean === $src) {
                continue;
            }
            ++$changed;
            $saved += \strlen($src) - \strlen($clean);
            if ($dry) {
                $io->writeln(sprintf('  %s: -%d байт', $path, \strlen($src) - \strlen($clean)));
                continue;
            }
            if (file_put_contents($path, $clean) === false) {
                $failed[] = $path;
            }
        }
        foreach ($skipped as $path) {
            $io->warning(sprintf('%s: розбір JS непевний — файл лишено з коментарями', $path));
        }
        foreach ($failed as $path) {
            $io->error(sprintf('%s: не вдалося записати', $path));
        }
        $io->writeln(sprintf('%s%d файлів css/js, змінено %d, прибрано %d байт', $dry ? '[dry-run] ' : '', $files, $changed, $saved));

        return $failed === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
