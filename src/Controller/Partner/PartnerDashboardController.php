<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Partner;

use App\Repository\ServiceLeadAssignmentRepository;
use App\Security\PartnerCabinetAccess;
use App\ServiceSite\Partners\PartnerBilling;
use App\ServiceSite\Partners\PartnersConfig;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use function Symfony\Component\Translation\t;

#[AdminDashboard(routePath: '/partner', routeName: 'partner', allowedControllers: [PartnerLeadCrudController::class])]
final class PartnerDashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly PartnerCabinetAccess $access,
        private readonly PartnersConfig $config,
        private readonly PartnerBilling $billing,
        private readonly ServiceLeadAssignmentRepository $assignments,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function index(): Response
    {
        $partner = $this->access->partner();
        $period = $this->billing->period(null);
        $monthCount = (int) $this->assignments->createQueryBuilder('a')->select('COUNT(a.id)')
            ->andWhere('a.partner = :partner')->andWhere('a.assignedAt >= :start AND a.assignedAt < :end')
            ->setParameter('partner', $partner)->setParameter('start', $period['start'])->setParameter('end', $period['end'])
            ->getQuery()->getSingleScalarResult();

        return $this->render('partner/dashboard.html.twig', ['company' => $partner->getName(),
            'new_count' => $this->assignments->count(['partner' => $partner, 'status' => 'new']),
            'accepted_count' => $this->assignments->count(['partner' => $partner, 'status' => 'accepted']), 'month_count' => $monthCount]);
    }

    public function configureDashboard(): Dashboard
    {
        $this->access->partner();

        return Dashboard::new()->setTitle($this->translator->trans('cabinet_title', [], 'partner'))->setTranslationDomain('partner')->setLocales([$this->config->cabinetLocale]);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard(t('home', [], 'partner'), 'fa fa-home');
        yield MenuItem::linkTo(PartnerLeadCrudController::class, t('leads', [], 'partner'), 'fa fa-phone');
        yield MenuItem::linkToRoute(t('billing', [], 'partner'), 'fa fa-file-invoice', 'partner_billing');
        yield MenuItem::linkToLogout(t('logout', [], 'partner'), 'fa fa-sign-out');
    }

    #[AdminRoute(path: '/billing', name: 'billing', allowedDashboards: [self::class], options: ['methods' => ['GET']])]
    public function billing(Request $request): Response
    {
        $report = $this->billing->report($this->access->partner(), $request->query->getString('month'));
        if ($request->query->get('format') === 'csv') {
            $stream = fopen('php://temp', 'w+');
            fputcsv($stream, ['assignment_id', 'assigned_at', 'zip_from', 'zip_to', 'status', 'price_cents', 'billed'], ',', '"', '');
            foreach ($report['rows'] as $row) {
                fputcsv($stream, array_values($row), ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);

            return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="billing-' . $report['month'] . '.csv"', 'Cache-Control' => 'private, no-store']);
        }

        return $this->render('partner/billing.html.twig', ['report' => $report]);
    }
}
