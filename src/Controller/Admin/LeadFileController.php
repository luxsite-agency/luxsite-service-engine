<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceLeadFile;
use App\ServiceSite\LeadFiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Файл заявки (app.lead_files) — лише модератору адмінки, лише на хості адмінки (AdminHostSubscriber, access_control
 * ^/admin). Посилання з листа менеджеру веде сюди: без входу — сторінка логіну. Зображення й PDF відкриваються в браузері
 * (inline), решта — завантаженням (і будь-який з ?download); nosniff, для зображень — sandbox-CSP, щоб файл від відвідувача не
 * виконав скрипт у домені адмінки (SVG і HTML app.lead_files не приймає взагалі).
 */
#[IsGranted('ROLE_MODERATOR')]
final class LeadFileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LeadFiles $files,
    ) {
    }

    #[Route(path: '/admin/lead-file/{id}', name: 'admin_lead_file', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(Request $request, int $id): BinaryFileResponse
    {
        $file = $this->em->find(ServiceLeadFile::class, $id) ?? throw $this->createNotFoundException('Файлу нема.');
        $path = $this->files->path($file) ?? throw $this->createNotFoundException('Файл видалено з диска.');
        $inline = str_starts_with($file->getMimeType(), 'image/') || $file->getMimeType() === 'application/pdf';
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => $file->getMimeType()], false);
        $response->setContentDisposition($inline && !$request->query->has('download') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getOriginalName(), 'file.' . pathinfo($path, \PATHINFO_EXTENSION));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (str_starts_with($file->getMimeType(), 'image/')) {
            // PDF — без sandbox: вбудований переглядач браузера в sandbox-документі не відкривається
            $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
