<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Entity\ServiceSite;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\RootSlugs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Збіги слагів у корені сайту (App\ServiceSite\RootSlugs::conflicts): дві послуги / категорія й послуга / сторінка й
 * категорія на одній адресі /<слаг>/ чи /ru/<слаг>/, або запис на адресі фіксованого розділу (/about/, /services/,
 * префікс блогу…). За такого збігу одна з адрес недосяжна. Адмінка нових збігів не пропускає; ця команда знаходить
 * ті, що прийшли імпортом/seed чи були до перемикання схеми. Код виходу 1 — є збіги (bin/site doctor → FAIL).
 */
#[AsCommand(name: 'app:site:slugs', description: 'Збіги слагів у корені сайту (послуги, категорії, сторінки, фіксовані розділи); 1 — є збіги')]
final class SiteSlugsCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RootSlugs $rootSlugs,
        private readonly CatalogUrls $catalogUrls,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('slug сайту (ServiceSite.slug); порожньо = усі сайти')] ?string $site = null,
    ): int {
        $criteria = $site !== null && $site !== '' ? ['slug' => $site] : [];
        $sites = $this->em->getRepository(ServiceSite::class)->findBy($criteria, ['id' => 'ASC']);
        if ($sites === [] && $criteria !== []) {
            $io->error('Сайту ' . $site . ' нема');

            return Command::INVALID;
        }
        $total = 0;
        foreach ($sites as $s) {
            foreach ($this->rootSlugs->conflicts($s) as $line) {
                $io->writeln('- ' . $s->getSlug() . ': ' . $line);
                ++$total;
            }
        }
        $what = array_filter([
            $this->rootSlugs->pagesEnabled() ? 'сторінки' : null,
            $this->catalogUrls->categoriesAtRoot() ? 'категорії' : null,
            $this->catalogUrls->itemsAtRoot() ? 'послуги' : null,
        ]);
        if ($total > 0) {
            $io->error(sprintf('Збігів слагів у корені: %d (у корені: %s)', $total, implode(', ', $what) ?: '—'));

            return Command::FAILURE;
        }
        $io->writeln(sprintf('OK: збігів слагів у корені нема (у корені: %s)', implode(', ', $what) ?: 'лише фіксовані розділи'));

        return Command::SUCCESS;
    }
}
