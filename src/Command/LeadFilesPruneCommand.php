<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Command;

use App\Entity\ServiceLeadFile;
use App\ServiceSite\LeadFiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Термін зберігання файлів заявок і анкет (персональні дані): файли, старші за app.lead_files.retention_days (або --days),
 * видаляються з диска й з БД, заявка чи анкета лишається. Ще прибирає «сироти» — файли в теці без запису в БД (заявку видалив каскад БД, а не ORM),
 * старші за добу. retention_days: 0 і без --days — лише сироти. Крон раз на добу (docs/site-ops.md).
 */
#[AsCommand(name: 'app:lead-files:prune', description: 'Файли заявок: видалити старші за термін зберігання (app.lead_files.retention_days) і файли без запису')]
final class LeadFilesPruneCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeadFiles $files,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Термін у днях замість app.lead_files.retention_days (0 — лише файли без запису)')] ?int $days = null,
        #[Option(name: 'dry-run', description: 'Лише показати, що буде видалено')] bool $dryRun = false,
    ): int {
        $days ??= (int) $this->files->config()['retention_days'];
        $expired = 0;
        if ($days > 0) {
            $old = $this->em->createQueryBuilder()->select('f')->from(ServiceLeadFile::class, 'f')
                ->where('f.createdAt < :before')->setParameter('before', new \DateTimeImmutable(sprintf('-%d days', $days)))
                ->getQuery()->getResult();
            foreach ($old as $file) {
                ++$expired;
                if (!$dryRun) {
                    $file->getLead()?->removeFile($file);
                    $file->getApplication()?->removeFile($file);
                    $this->em->remove($file); // файл з диска — LeadFiles::postRemove
                }
            }
            if (!$dryRun) {
                $this->em->flush();
            }
        }

        $orphans = 0;
        $dir = $this->files->dir();
        if (is_dir($dir)) {
            $known = array_flip(array_column($this->em->createQueryBuilder()->select('f.storedName')->from(ServiceLeadFile::class, 'f')->getQuery()->getScalarResult(), 'storedName'));
            foreach (new \DirectoryIterator($dir) as $entry) {
                if (!$entry->isFile() || isset($known[$entry->getFilename()]) || $entry->getMTime() > time() - 86400
                    || preg_match('/^[0-9a-f]{32}\.[a-z]{3,4}$/', $entry->getFilename()) !== 1) {
                    continue;
                }
                ++$orphans;
                if (!$dryRun) {
                    @unlink($entry->getPathname());
                }
            }
        }

        $io->success(sprintf('%sфайлів за терміном (%s): %d; без запису: %d.', $dryRun ? '[dry-run] ' : '', $days > 0 ? $days . ' дн.' : 'вимкнено', $expired, $orphans));

        return Command::SUCCESS;
    }
}
