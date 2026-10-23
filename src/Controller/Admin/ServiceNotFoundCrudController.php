<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceNotFound;
use App\Repository\ServiceSiteRepository;
use App\ServiceSite\Redirect\NotFoundLog;
use App\Settings\AdminBranding;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Журнал 404 сайту (SEO, модель shop-engine E3): адреси, які на хості міста віддали 404 і не мають правила, — зі
 * зверненнями й останнім referer. З рядка — «Створити редирект» (форма з підставленими адресою, сайтом і містом);
 * правило знімає адресу з журналу. Обсяг на сайт обмежено автоматично.
 *
 * @extends AbstractCrudController<ServiceNotFound>
 */
#[IsGranted('ROLE_MODERATOR')]
final class ServiceNotFoundCrudController extends AbstractCrudController
{
    use FieldHintTrait;

    public function __construct(
        private readonly NotFoundLog $log,
        private readonly ServiceSiteRepository $sites,
        private readonly AdminBranding $branding,
        #[Autowire('%app.seo_not_found_limit%')] private readonly int $limit,
        #[Autowire('%app.seo_not_found_days%')] private readonly int $days,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceNotFound::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Адреса 404')
            ->setEntityLabelInPlural('Журнал 404')
            ->setSearchFields(['path', 'referer'])
            ->setDefaultSort(['hits' => 'DESC', 'lastHitAt' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setEntityPermission('ROLE_MODERATOR')
            ->setHelp(Crud::PAGE_INDEX, sprintf('Адреси сайту, що віддали 404 на хості міста, без IP і параметрів; сканування ботів і службові шляхи не пишуться. Зберігається до %d адрес на сайт і %d днів з останнього звернення; решта прибирається сама. Створіть редирект з рядка — адреса зникне з журналу.', $this->limit, $this->days));
    }

    public function configureActions(Actions $actions): Actions
    {
        $redirect = Action::new('createRedirect', 'Створити редирект', 'fa fa-share')->linkToCrudAction('createRedirect');
        $clear = Action::new('clear', 'Очистити журнал', 'fa fa-trash')->linkToCrudAction('clear')->createAsGlobalAction()->setCssClass('btn btn-danger');

        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, $redirect)
            ->add(Crud::PAGE_INDEX, $clear);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('site', 'Сайт'))
            ->add(EntityFilter::new('city', 'Місто'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield from $this->withHints($this->fieldList());
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(): iterable
    {
        $site = AssociationField::new('site', 'Сайт');
        if ($this->branding->singleSite()) {
            $site->hideOnIndex();
        }
        yield $site;
        yield AssociationField::new('city', 'Місто')->setHelp('Хост міста, на якому адреса віддала 404.');
        yield TextField::new('path', 'Адреса');
        yield IntegerField::new('hits', 'Звернень');
        yield DateTimeField::new('lastHitAt', 'Останнє');
        yield DateTimeField::new('firstSeenAt', 'Вперше')->hideOnIndex();
        yield TextField::new('referer', 'Звідки (останнє)')->setHelp('Сторінка, з якої перейшли, без параметрів.');
    }

    /** @param AdminContext<ServiceNotFound> $context */
    #[AdminRoute(path: '/{entityId}/create-redirect', name: 'create_redirect')]
    public function createRedirect(AdminContext $context): Response
    {
        $entry = $context->getEntity()->getInstance();
        if (!$entry instanceof ServiceNotFound) {
            throw $this->createNotFoundException();
        }

        return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()
            ->setController(ServiceRedirectCrudController::class)->setAction(Action::NEW)
            ->set('source', $entry->getPath())->set('site', $entry->getSite()?->getId())->set('city', $entry->getCity()?->getId())->generateUrl());
    }

    #[AdminRoute(path: '/clear', name: 'clear', options: ['methods' => ['GET', 'POST']])]
    public function clear(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $site = $this->sites->find((int) $request->request->get('site', 0));
            if (!$this->isCsrfTokenValid('seo-not-found-clear', (string) $request->request->get('token', ''))) {
                $this->addFlash('danger', 'Недійсний CSRF-токен, спробуйте ще раз');
            } elseif ($site === null) {
                $this->addFlash('danger', 'Оберіть сайт');
            } else {
                $this->addFlash('success', sprintf('Журнал 404 «%s» очищено: %d адрес', htmlspecialchars($site->getName(), \ENT_QUOTES), $this->log->clear((int) $site->getId())));
            }

            return $this->redirect($this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl());
        }

        return $this->render('admin/seo/not_found_clear.html.twig', [
            'sites' => $this->sites->findBy([], ['id' => 'ASC']),
            'current' => $this->sites->find((int) $request->query->get('site', 0)) ?? $this->branding->site(),
        ]);
    }
}
