<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ServiceSiteRepository;
use App\Security\GoogleOAuthConfig;
use App\Settings\AdminBranding;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response;

use function Symfony\Component\Translation\t;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminBranding $branding,
        private readonly GoogleOAuthConfig $googleOAuth,
        private readonly ServiceSiteRepository $sites,
        private readonly AdminUrlGeneratorInterface $adminUrls,
    ) {
    }

    public function index(): Response
    {
        // «Вхід через Google не налаштовано» — по кожному активному сайту, з посиланням на його налаштування (лише адміну)
        $sites = $this->sites->findAllActive();
        $google = [];
        foreach ($sites as $site) {
            if (!$this->googleOAuth->isConfigured($site)) {
                $google[] = [
                    'site' => \count($sites) > 1 ? $site->getName() : null,
                    'url' => $this->adminUrls->unsetAll()->setController(SiteSettingCrudController::class)->setAction('index')->set('site', $site->getId())->generateUrl() . '#google-sign-in',
                ];
            }
        }

        return $this->render('admin/dashboard.html.twig', ['google_warnings' => $google, 'admin_title' => $this->branding->title()]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            // назва сайту адмінки (AdminBranding; налаштування «Назва адмінки»), кілька сайтів — «Сервісні сайти». Title EasyAdmin — HTML
            ->setTitle(htmlspecialchars($this->branding->title(), \ENT_QUOTES))
            ->setLocales(['uk']);
    }

    public function configureCrud(): Crud
    {
        // повна ширина контенту: пари uk|ru у 2 колонки потребують місця
        return Crud::new()->renderContentMaximized();
    }

    public function configureAssets(): Assets
    {
        // відкриття таба з помилкою валідації після сабміту.
        // ⚠️ не класти в public/admin/ - реальна тека затіняє роут /admin (nginx $uri/)
        return Assets::new()
            ->addJsFile('assets/admin-tabs.js')
            // кругла підказка біля назв колонок у списках
            ->addJsFile('assets/admin-hints.js');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Дашборд', 'fa fa-home');

        yield MenuItem::section('Контент');
        yield MenuItem::linkTo(ServiceCategoryCrudController::class, 'Категорії послуг', 'fa fa-folder-tree');
        yield MenuItem::linkTo(ServiceItemCrudController::class, 'Послуги', 'fa fa-screwdriver-wrench');
        yield MenuItem::linkTo(ServiceItemCityCrudController::class, 'Послуги: тексти по містах', 'fa fa-city');
        yield MenuItem::linkTo(ServiceCategoryCityCrudController::class, 'Категорії: тексти по містах', 'fa fa-city');
        yield MenuItem::linkTo(ServicePriceCrudController::class, 'Прайс', 'fa fa-coins');
        yield MenuItem::linkTo(ServiceDistrictCrudController::class, 'Райони', 'fa fa-map');
        yield MenuItem::linkTo(ServiceReviewCrudController::class, 'Відгуки клієнтів', 'fa fa-star-half-stroke');
        yield MenuItem::linkTo(ServiceTeamMemberCrudController::class, 'Команда', 'fa fa-people-group');
        yield MenuItem::linkTo(ServicePostCrudController::class, 'Блог', 'fa fa-pen');
        yield MenuItem::linkTo(ServiceWorkCrudController::class, 'Наші роботи', 'fa fa-camera');

        yield MenuItem::section('Калькулятор');
        yield MenuItem::linkTo(ServiceCalcTypeCrudController::class, 'Типи: базові ціни', 'fa fa-broom');
        yield MenuItem::linkTo(ServiceCalcTypeCityCrudController::class, 'Типи: ціни по містах', 'fa fa-city');
        yield MenuItem::linkTo(ServiceCalcFactorCrudController::class, 'Коефіцієнти', 'fa fa-percent');
        // Додаткові опції калькулятора переїхали в «Прайс»: один рядок - і
        // таблиця на сторінці послуги, і позиція в розрахунку. Стара таблиця
        // лишається кодом як запасний варіант, але з меню прибрана, щоб не
        // правили ціни у двох місцях.

        yield MenuItem::section('Заявки')->setPermission('ROLE_MODERATOR');
        yield MenuItem::linkTo(ServiceLeadCrudController::class, 'Заявки', 'fa fa-phone-volume')
            ->setPermission('ROLE_MODERATOR');

        yield MenuItem::section('API')->setPermission('ROLE_MODERATOR');
        yield MenuItem::linkTo(AdminApiTokenCrudController::class, 'API-токени', 'fa fa-key')
            ->setPermission('ROLE_MODERATOR');

        yield MenuItem::section('Мережа')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ServiceSiteCrudController::class, $this->branding->singleSite() ? 'Сайт' : 'Сайти-бренди', 'fa fa-briefcase')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ServiceCityCrudController::class, 'Міста сервісів', 'fa fa-location-dot')
            ->setPermission('ROLE_ADMIN');

        yield MenuItem::section('Налаштування')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(SiteSettingCrudController::class, t('menu.settings', [], 'admin'), 'fa fa-sliders')
            ->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(UserCrudController::class, 'Користувачі', 'fa fa-users')
            ->setPermission('ROLE_ADMIN');

        yield MenuItem::section();
        yield MenuItem::linkToLogout('Вийти', 'fa fa-sign-out');
    }
}
