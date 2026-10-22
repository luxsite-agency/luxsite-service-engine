<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\AdminApiToken;
use App\Entity\User;
use App\Form\NewApiTokenType;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * API-токени: менеджер бачить і створює лише свої, адміністратор бачить усі й відкликає будь-який. Значення токена
 * показується один раз після створення (через сесію), далі — лише префікс. Стандартні new/edit/delete вимкнені:
 * створення — власна форма (issue), відкликання — POST з CSRF (revoke), рядки не видаляються (історія доступів).
 *
 * @extends AbstractCrudController<AdminApiToken>
 */
final class AdminApiTokenCrudController extends AbstractCrudController
{
    public const string SESSION_PLAIN = 'api_token_plain';
    private const string REVOKE_CSRF = 'api-token-revoke';

    public function __construct(private readonly AdminApiTokens $tokens)
    {
    }

    public static function getEntityFqcn(): string
    {
        return AdminApiToken::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('API-токен')
            ->setEntityLabelInPlural('API-токени')
            ->setSearchFields(['name', 'prefix'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->overrideTemplate('crud/index', 'admin/api_token/index.html.twig')
            ->setHelp(Crud::PAGE_INDEX, sprintf('Персональні токени для content-studio, Claude Code і скриптів (docs/admin-api.md): кожен створює собі, строк до %d днів, значення показується один раз. Адміністратор бачить усі токени й може відкликати будь-який.', $this->tokens->maxDays()));
    }

    public function configureActions(Actions $actions): Actions
    {
        $issue = Action::new('issue', 'Створити токен', 'fa fa-plus')
            ->linkToCrudAction('issue')
            ->createAsGlobalAction()
            ->asPrimaryAction();
        $revoke = Action::new('revoke', 'Відкликати', 'fa fa-ban')
            ->linkToCrudAction('revoke')
            ->displayIf(static fn (AdminApiToken $t): bool => !$t->isRevoked());

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $issue)
            ->add(Crud::PAGE_INDEX, $revoke);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $filters->add(ChoiceFilter::new('scopes', 'Дозвіл')->setChoices(ApiScope::choices()));
        if ($this->isGranted('ROLE_ADMIN')) {
            $filters->add(EntityFilter::new('user', 'Власник'));
        }

        return $filters;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Назва');
        yield TextField::new('maskedToken', 'Токен')->setHelp('Перші 12 символів — щоб упізнати; повне значення не зберігається');
        yield TextField::new('scopesText', 'Дозволи');
        if ($this->isGranted('ROLE_ADMIN')) {
            yield TextField::new('ownerName', 'Власник');
        }
        yield TextField::new('statusLabel', 'Стан');
        yield DateTimeField::new('createdAt', 'Створено');
        yield DateTimeField::new('expiresAt', 'Діє до');
        yield DateTimeField::new('lastUsedAt', 'Останній запит');
        yield TextField::new('lastUsedIp', 'З адреси')->setHelp('Анонімізовано');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if ($responseParameters->get('pageName') === Crud::PAGE_INDEX) {
            $session = $this->container->get('request_stack')->getSession();
            $plain = $session->get(self::SESSION_PLAIN);
            $session->remove(self::SESSION_PLAIN);
            $responseParameters->set('token_plain', \is_string($plain) ? $plain : null);
        }

        return $responseParameters;
    }

    /** Менеджер — лише свої токени; адмін — усі. */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        if (!$this->isGranted('ROLE_ADMIN')) {
            $qb->andWhere('entity.user = :me')->setParameter('me', $this->currentUser());
        }

        return $qb;
    }

    #[AdminRoute(path: '/issue', name: 'issue', options: ['methods' => ['GET', 'POST']])]
    public function issue(Request $request): Response
    {
        $user = $this->currentUser();
        // бренди, доступні користувачу (User.sites; 22.10.2026) — вибір у формі, лише коли їх більше одного
        $sites = [];
        foreach ($this->container->get('doctrine')->getRepository(\App\Entity\ServiceSite::class)->findAllActive() as $site) {
            if ($user->canAccessSite($site)) {
                $sites[$site->getName() . ' (' . $site->getSlug() . ')'] = $site->getSlug();
            }
        }
        $form = $this->createForm(NewApiTokenType::class, null, ['scopes' => $this->tokens->allowedScopes($user), 'max_days' => $this->tokens->maxDays(), 'sites' => $sites]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string, scopes: list<ApiScope>, days: int, sites?: list<string>} $data */
            $data = $form->getData();
            try {
                $issued = $this->tokens->issue($user, $data['name'], $data['scopes'], $data['days'], $user, $data['sites'] ?? null);
                // значення — один раз, через сесію (на сервері), на сторінці списку; у БД і логах його нема
                $request->getSession()->set(self::SESSION_PLAIN, $issued->plain);
                $this->addFlash('success', sprintf('Токен «%s» створено. Скопіюйте значення зараз — удруге воно не покажеться.', $issued->token->getName()));

                return $this->redirect($this->indexUrl());
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('admin/api_token/issue.html.twig', ['form' => $form, 'max_days' => $this->tokens->maxDays(), 'index_url' => $this->indexUrl()]);
    }

    /** GET ?entityId= — сторінка підтвердження; POST (id + CSRF) — відкликання. Чужий токен — лише ROLE_ADMIN. */
    #[AdminRoute(path: '/revoke', name: 'revoke', options: ['methods' => ['GET', 'POST']])]
    public function revoke(Request $request): Response
    {
        $id = (int) ($request->isMethod('POST') ? $request->request->get('id', 0) : $request->query->get('entityId', 0));
        $token = $this->container->get('doctrine')->getRepository(AdminApiToken::class)->find($id);
        if (!$token instanceof AdminApiToken) {
            throw $this->createNotFoundException('Токен не знайдено');
        }
        $user = $this->currentUser();
        if ($token->getUser() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            throw new AccessDeniedException('Чужий токен відкликає лише адміністратор');
        }
        if (!$request->isMethod('POST')) {
            $revokeUrl = $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction('revoke')->generateUrl();

            return $this->render('admin/api_token/revoke.html.twig', ['token' => $token, 'index_url' => $this->indexUrl(), 'revoke_url' => $revokeUrl]);
        }
        if (!$this->isCsrfTokenValid(self::REVOKE_CSRF, (string) $request->request->get('token', ''))) {
            $this->addFlash('danger', 'Недійсний CSRF-токен, спробуйте ще раз');

            return $this->redirect($this->indexUrl());
        }
        $this->tokens->revoke($token, $user);
        $this->addFlash('success', sprintf('Токен «%s» відкликано', $token->getName()));

        return $this->redirect($this->indexUrl());
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $user;
    }

    private function indexUrl(): string
    {
        return $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl();
    }
}
