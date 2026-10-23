<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCity;
use App\Entity\ServiceRedirect;
use App\Entity\ServiceSite;
use App\Repository\ServiceSiteRepository;
use App\ServiceSite\Redirect\InvalidRedirect;
use App\ServiceSite\Redirect\PackRedirects;
use App\ServiceSite\Redirect\RedirectCode;
use App\ServiceSite\Redirect\RedirectCsv;
use App\ServiceSite\Redirect\RedirectKind;
use App\ServiceSite\Redirect\RedirectManager;
use App\Settings\AdminBranding;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Менеджер редиректів (SEO, модель shop-engine E3): правила вручну, авто-301 і перенесені з паку; фільтри, CSV-експорт та
 * імпорт зі звітом, «Перенести з паку». Збереження зливає ланцюги (RedirectManager): ціль одразу кінцева, правила, що вели
 * на це джерело, переписуються на ту саму ціль. Правило належить сайту; місто — лише для його хоста.
 *
 * @extends AbstractCrudController<ServiceRedirect>
 */
#[IsGranted('ROLE_MODERATOR')]
final class ServiceRedirectCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    public function __construct(
        private readonly RedirectManager $redirects,
        private readonly RedirectCsv $csv,
        private readonly PackRedirects $pack,
        private readonly ServiceSiteRepository $sites,
        private readonly AdminBranding $branding,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceRedirect::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Редирект')
            ->setEntityLabelInPlural('Редиректи')
            ->setSearchFields(['source', 'target'])
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setEntityPermission('ROLE_MODERATOR')
            ->setHelp(Crud::PAGE_INDEX, 'Правило спрацьовує лише тоді, коли адреса на хості міста віддала б 404: живі сторінки й правила паку (app.legacy_redirects, слеш, 410-префікси) не перекриваються. Зміна слага послуги, категорії, поста чи району записує 301 сама (авто). Видалену сторінку — 410 вручну, якщо треба. Місто порожнє = правило на всіх містах сайту; задане — лише на його хості (має перевагу).');
    }

    public function configureActions(Actions $actions): Actions
    {
        $export = Action::new('export', 'Експорт CSV', 'fa fa-download')->linkToCrudAction('export')->createAsGlobalAction();
        $import = Action::new('import', 'Імпорт CSV', 'fa fa-upload')->linkToCrudAction('import')->createAsGlobalAction();
        $pack = Action::new('fromPack', 'Перенести з паку', 'fa fa-box-open')->linkToCrudAction('fromPack')->createAsGlobalAction();

        return $this->addCancelAction($actions)
            ->add(Crud::PAGE_INDEX, $pack)
            ->add(Crud::PAGE_INDEX, $import)
            ->add(Crud::PAGE_INDEX, $export);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('site', 'Сайт'))
            ->add(EntityFilter::new('city', 'Місто'))
            ->add(ChoiceFilter::new('code', 'Код')->setChoices(self::codeChoices()))
            ->add(ChoiceFilter::new('kind', 'Звідки')->setChoices(self::kindChoices()));
    }

    public function configureFields(string $pageName): iterable
    {
        yield from $this->withHints($this->fieldList());
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        $site = AssociationField::new('site', 'Сайт')->setColumns(6)
            ->setHelp('Сайт-бренд, якого стосується правило.');
        if ($this->branding->singleSite()) {
            $site->hideOnIndex();
        }
        yield $site;
        yield AssociationField::new('city', 'Місто')->setColumns(6)->setRequired(false)
            ->setFormTypeOption('placeholder', 'усі міста сайту')
            ->setFormTypeOption('choice_label', static fn (ServiceCity $c): string => $c->getNameNomUk() . ' (' . $c->getHost() . ')')
            ->setHelp('Порожньо = правило діє на всіх хостах сайту (послуги, пости, категорії — їхні адреси однакові в усіх містах). Місто — лише на його хості (райони, сторінки одного міста); правило міста має перевагу над правилом сайту.');
        yield TextField::new('source', 'Стара адреса')
            ->setHelp('Шлях від кореня сайту, з /ru/ для російської: /stara-adresa/, /ru/services/x/. Можна вставити повний URL — домен і параметри відкинуться, регістр — до нижнього. Зі слешем чи без — правило спрацює в обох формах.')
            ->setFormTypeOption('setter', static fn (ServiceRedirect $r, ?string $v) => $r->setSource($v))
            ->setFormTypeOption('getter', static fn (ServiceRedirect $r): string => $r->getSource() === '' ? (string) $r->getRawSource() : $r->getSource());
        yield ChoiceField::new('code', 'Код')->setChoices(self::codeChoices())
            ->setFormTypeOption('choice_value', static fn (?RedirectCode $c): ?string => $c === null ? null : (string) $c->value)
            ->renderAsBadges([301 => 'success', 302 => 'info', 404 => 'secondary', 410 => 'danger'])
            ->setHelp('301 — назавжди (пошук передає вагу новій адресі), 302 — тимчасово, 410 — сторінку прибрано назовсім, 404 — звичайна 404 без запису в журнал.');
        yield TextField::new('target', 'Нова адреса')
            ->setRequired(false)
            ->setHelp('Для 301/302: шлях від кореня (/nova-adresa/) або повний URL https://… Для 404/410 — порожньо. Якщо нова адреса сама переадресовується, збережеться кінцева.')
            ->setFormTypeOption('setter', static fn (ServiceRedirect $r, ?string $v) => $r->setTarget($v))
            ->setFormTypeOption('getter', static fn (ServiceRedirect $r): ?string => $r->getTarget() ?? $r->getRawTarget());
        yield ChoiceField::new('kind', 'Звідки')->setChoices(self::kindChoices())
            ->renderAsBadges(['manual' => 'secondary', 'auto' => 'primary', 'pack' => 'warning'])->hideOnForm()
            ->setHelp('вручну — адмінка, CSV, API; авто — рушій записав при зміні слага; з паку — перенесено з app.legacy_redirects.');
        yield IntegerField::new('hits', 'Спрацювань')->hideOnForm();
        yield DateTimeField::new('lastHitAt', 'Останнє')->hideOnForm();
        yield DateTimeField::new('createdAt', 'Створено')->onlyOnDetail();
    }

    public function createEntity(string $entityFqcn): ServiceRedirect
    {
        // «Створити редирект» з журналу 404 відкриває форму з адресою, сайтом і містом (?source=…&site=…&city=…)
        $request = $this->getContext()?->getRequest();
        $site = $this->sites->find((int) ($request?->query->get('site') ?? 0)) ?? $this->branding->site() ?? $this->sites->findOneBy([], ['id' => 'ASC']);
        $cityId = (int) ($request?->query->get('city') ?? 0);
        $city = $cityId > 0 ? $this->container->get('doctrine')->getRepository(ServiceCity::class)->find($cityId) : null;

        return new ServiceRedirect($site, (string) ($request?->query->get('source') ?? ''), RedirectCode::Permanent, null, $city instanceof ServiceCity && $city->getSite()?->getId() === $site?->getId() ? $city : null);
    }

    public function new(AdminContext $context): KeyValueStore|Response
    {
        try {
            return parent::new($context);
        } catch (InvalidRedirect $e) {
            return $this->backToForm($context, $e);
        }
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        try {
            return parent::edit($context);
        } catch (InvalidRedirect $e) {
            return $this->backToForm($context, $e);
        }
    }

    /** Відмова під блокуванням уже після валідації форми (гонка двох форм): повідомлення у флеш і назад на форму, не 500. */
    private function backToForm(AdminContext $context, InvalidRedirect $e): Response
    {
        $this->addFlash('danger', 'Правило не збережено: ' . htmlspecialchars($e->getMessage(), \ENT_QUOTES)); // флеші EasyAdmin — |raw

        return $this->redirect($context->getRequest()->getUri());
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->redirects->locked(function () use ($entityManager, $entityInstance): void {
            $this->prepare($entityInstance);
            parent::persistEntity($entityManager, $entityInstance);
            $this->redirects->merge($this->siteOf($entityInstance), $entityInstance->getCity(), $entityInstance->getSource(), $entityInstance->getCode(), $entityInstance->getTarget());
        });
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->redirects->locked(function () use ($entityManager, $entityInstance): void {
            $this->prepare($entityInstance);
            $entityInstance->setKind(RedirectKind::Manual); // правило, змінене людиною, — ручне
            parent::updateEntity($entityManager, $entityInstance);
            $this->redirects->merge($this->siteOf($entityInstance), $entityInstance->getCity(), $entityInstance->getSource(), $entityInstance->getCode(), $entityInstance->getTarget());
        });
    }

    #[AdminRoute(path: '/export', name: 'export')]
    public function export(Request $request): Response
    {
        $site = $this->siteFromQuery($request);
        $response = new StreamedResponse(function () use ($site): void {
            $out = fopen('php://output', 'w');
            if ($out !== false) {
                $this->csv->export($site, $out);
                fclose($out);
            }
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'redirects-' . $site->getSlug() . '-' . date('Y-m-d') . '.csv'));

        return $response;
    }

    #[AdminRoute(path: '/import', name: 'import', options: ['methods' => ['GET', 'POST']])]
    public function import(Request $request): Response
    {
        $form = $this->createFormBuilder()
            ->add('site', EntityType::class, ['class' => ServiceSite::class, 'label' => 'Сайт', 'data' => $this->siteFromQuery($request), 'choice_label' => 'name'])
            ->add('file', FileType::class, [
                'label' => 'Файл CSV',
                'help' => sprintf('Рядки «стара;код;нова[;місто]» з роздільником «;», кодування UTF-8 (у Excel — «CSV UTF-8»): /stara/;301;/nova/, /vydalena/;410;, /rayon/;301;/districts/x/;kyiv (slug міста — правило лише для його хоста; порожньо = усі міста). Заголовок source;code;target;city можна лишити — файл експорту імпортується як є. Наявні правила з тією ж старою адресою замінюються. До %d рядків; більші файли — командою app:seo:redirects:import на сервері.', RedirectCsv::WEB_MAX_ROWS),
                'constraints' => [new NotNull(message: 'Оберіть файл'), new File(maxSize: '1M', extensions: ['csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], 'txt' => ['text/plain', 'text/csv']])],
            ])
            ->getForm();
        $form->handleRequest($request);
        $report = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $file = $form->get('file')->getData();
            $site = $form->get('site')->getData();
            try {
                $report = $file instanceof UploadedFile && $site instanceof ServiceSite ? $this->csv->import($site, $file->getPathname(), RedirectCsv::WEB_MAX_ROWS, $this->isGranted('ROLE_ADMIN')) : null;
            } catch (\RuntimeException $e) {
                $this->addFlash('danger', htmlspecialchars($e->getMessage(), \ENT_QUOTES)); // флеші EasyAdmin — |raw
            }
        }

        return $this->render('admin/seo/redirect_import.html.twig', ['form' => $form, 'report' => $report]);
    }

    /** Перенести app.legacy_redirects і app.gone_prefixes паку в БД як правила «з паку» (ідемпотентно; PackRedirects). */
    #[AdminRoute(path: '/from-pack', name: 'from_pack', options: ['methods' => ['GET', 'POST']])]
    public function fromPack(Request $request): Response
    {
        $sites = $this->sites->findBy([], ['id' => 'ASC']);
        $report = null;
        if ($request->isMethod('POST')) {
            $site = $this->sites->find((int) $request->request->get('site', 0));
            if (!$this->isCsrfTokenValid('seo-redirects-from-pack', (string) $request->request->get('token', ''))) {
                $this->addFlash('danger', 'Недійсний CSRF-токен, спробуйте ще раз');
            } elseif ($site === null) {
                $this->addFlash('danger', 'Оберіть сайт');
            } else {
                $report = $this->pack->import($site);
                $this->addFlash('success', sprintf('Перенесено з паку для «%s»: створено %d, оновлено %d, пропущено %d, з помилками %d', htmlspecialchars($site->getName(), \ENT_QUOTES), $report['created'], $report['updated'], \count($report['skipped']), \count($report['errors'])));
            }
        }

        return $this->render('admin/seo/redirect_from_pack.html.twig', ['sites' => $sites, 'current' => $this->siteFromQuery($request), 'count' => $this->pack->count(), 'report' => $report]);
    }

    /**
     * Під блокуванням: перевірка ще раз (між валідацією форми й записом інша форма могла зберегти зустрічне правило) і
     * кінцева ціль ланцюга. Відмова тут — лише в гонці двох форм.
     */
    private function prepare(ServiceRedirect $redirect): void
    {
        $site = $this->siteOf($redirect);
        $target = $redirect->getTarget() === null ? null : $this->redirects->localize($site, $redirect->getTarget());
        if (($error = $this->redirects->check($site, $redirect->getCity(), $redirect->getSource(), $redirect->getCode(), $target)) !== null) {
            throw new InvalidRedirect($error);
        }
        if (!$redirect->getCode()->isRedirect()) {
            $redirect->dropTarget();
        } elseif ($target !== null) {
            $redirect->retarget($this->redirects->resolve($site, $redirect->getCity(), $redirect->getSource(), $target));
        }
    }

    private function siteOf(ServiceRedirect $redirect): ServiceSite
    {
        return $redirect->getSite() ?? throw new InvalidRedirect('Оберіть сайт');
    }

    /** Сайт для експорту/імпорту: ?site=<id> → сайт адмінки (AdminBranding) → перший. */
    private function siteFromQuery(Request $request): ServiceSite
    {
        return $this->sites->find((int) $request->query->get('site', 0)) ?? $this->branding->site() ?? $this->sites->findOneBy([], ['id' => 'ASC'])
            ?? throw $this->createNotFoundException('Сайтів ще нема');
    }

    /** @return array<string, RedirectCode> */
    public static function codeChoices(): array
    {
        return array_combine(array_map(static fn (RedirectCode $c): string => $c->label(), RedirectCode::cases()), RedirectCode::cases());
    }

    /** @return array<string, RedirectKind> */
    private static function kindChoices(): array
    {
        return array_combine(array_map(static fn (RedirectKind $k): string => $k->label(), RedirectKind::cases()), RedirectKind::cases());
    }
}
