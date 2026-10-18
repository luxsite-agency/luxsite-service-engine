<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\ServiceSite\LegacyUrls;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Редиректи паку: seo/legacy-redirects.yaml (`legacy_redirects: {старий шлях: новий шлях}` — так їх зручно
 * знімати з .htaccess і правити руками) → блок app.legacy_redirects у config/parameters.yaml паку (формат
 * LegacyUrls: ключ без слешів, значення — цільовий шлях). Блок між маркерами перезаписується повністю,
 * решта файлу (коментарі, інші параметри) не чіпається. Перевірки: ціль починається зі слеша, не дорівнює
 * джерелу, ланцюжків A→B→C нема, дублікатів ключів (з/без слеша, регістр) нема.
 */
#[AsCommand(name: 'app:site:redirects', description: 'Зібрати app.legacy_redirects паку з seo/legacy-redirects.yaml')]
final class SiteRedirectsCommand extends Command
{
    private const BEGIN = '    # >>> app.legacy_redirects: згенеровано app:site:redirects із seo/legacy-redirects.yaml — не правити руками';
    private const END = '    # <<< app.legacy_redirects';

    protected function configure(): void
    {
        $this
            ->addArgument('pack', InputArgument::REQUIRED, 'Тека паку сайту')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Файл правил відносно паку', 'seo/legacy-redirects.yaml')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Лише перевірити правила й порівняти з parameters.yaml, нічого не писати');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pack = rtrim((string) $input->getArgument('pack'), '/\\');
        $src = $pack . '/' . ltrim((string) $input->getOption('source'), '/');
        $dst = $pack . '/config/parameters.yaml';
        foreach ([$src, $dst] as $f) {
            if (!is_file($f)) {
                $io->error('Нема файлу ' . $f);

                return Command::INVALID;
            }
        }

        $data = Yaml::parseFile($src);
        $rules = \is_array($data) ? ($data['legacy_redirects'] ?? null) : null;
        if (!\is_array($rules)) {
            $io->error('У ' . $src . ' очікується ключ legacy_redirects: {старий шлях: новий шлях}');

            return Command::INVALID;
        }

        $map = [];
        $errors = [];
        foreach ($rules as $old => $new) {
            $key = LegacyUrls::key((string) $old);
            $target = trim((string) $new);
            if ($key === '') {
                $errors[] = sprintf('«%s»: порожній старий шлях', $old);
                continue;
            }
            if ($target === '' || ($target[0] !== '/' && !preg_match('#^https?://#', $target))) {
                $errors[] = sprintf('%s → «%s»: ціль має починатися зі слеша', $old, $new);
                continue;
            }
            if (LegacyUrls::key($target) === $key) {
                $errors[] = sprintf('%s → %s: ціль збігається з джерелом', $old, $new);
                continue;
            }
            if (isset($map[$key])) {
                $errors[] = sprintf('%s: дублікат ключа (той самий шлях з/без слеша або в іншому регістрі)', $old);
                continue;
            }
            $map[$key] = $target;
        }
        foreach ($map as $key => $target) {
            if (!preg_match('#^https?://#', $target) && isset($map[LegacyUrls::key($target)])) {
                $errors[] = sprintf('%s → %s: ціль сама є старим шляхом (ланцюжок 301 → 301) — вкажіть кінцеву адресу', $key, $target);
            }
        }
        if ($errors !== []) {
            $io->error(sprintf('Правила з помилками (%d):', \count($errors)));
            $io->listing($errors);

            return Command::FAILURE;
        }
        ksort($map, SORT_STRING);

        $lines = [self::BEGIN, '    app.legacy_redirects:'];
        foreach ($map as $key => $target) {
            $lines[] = '        ' . Yaml::dump((string) $key) . ': ' . Yaml::dump($target);
        }
        $lines[] = self::END;
        $block = implode("\n", $lines) . "\n";

        $current = (string) file_get_contents($dst);
        $pattern = '#' . preg_quote(self::BEGIN, '#') . '.*?' . preg_quote(self::END, '#') . '\n?#s';
        $next = preg_match($pattern, $current)
            ? (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\$'], $block), $current, 1)
            : rtrim($current, "\n") . "\n" . $block;

        $parsed = Yaml::parse($next);
        if (!\is_array($parsed) || !isset($parsed['parameters']['app.legacy_redirects']) || \count($parsed['parameters']['app.legacy_redirects']) !== \count($map)) {
            $io->error('Після вставки блоку parameters.yaml не читається як очікувано — файл не змінено.');

            return Command::FAILURE;
        }

        if ($input->getOption('check')) {
            $same = $next === $current;
            $io->{$same ? 'success' : 'warning'}(sprintf('Правил: %d; parameters.yaml %s.', \count($map), $same ? 'актуальний' : 'ЗАСТАРІВ — запустіть без --check'));

            return $same ? Command::SUCCESS : Command::FAILURE;
        }
        file_put_contents($dst, $next);
        $io->success(sprintf('Правил: %d → %s (блок app.legacy_redirects).', \count($map), $dst));

        return Command::SUCCESS;
    }
}
