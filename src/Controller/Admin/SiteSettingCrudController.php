<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCity;
use App\Entity\ServiceSite;
use App\Entity\ServiceSiteSetting;
use App\Entity\User;
use App\Repository\ServiceSiteRepository;
use App\ServiceSite\Crm\LeadCrmConnector;
use App\Settings\AdminBranding;
use App\Settings\GoogleSignInPanel;
use App\Settings\SecretBox;
use App\Settings\SecretState;
use App\Settings\SiteSettingKeys;
use App\Settings\SiteSettings;
use App\Settings\SiteTagResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/**
 * Налаштування сайту (21.10.2026, модель shop-engine SettingCrudController; лише ROLE_ADMIN — токени менеджерів сюди не
 * ведуть: REST/MCP живуть в окремому firewall і налаштувань не мають). Один рядок на ключ SiteSettingKeys для обраного
 * сайту (в одній БД може бути кілька сайтів-брендів: ?site=<id>, вибір пам'ятається в сесії), лише редагування значення.
 * Секрети: поле пароля завжди порожнє, порожнє при збереженні = лишити чинне, у списку — маска; без SETTINGS_SECRET_KEY
 * поле неактивне. Формат id перевіряється при збереженні (помилка біля поля). Кожна зміна — у журнал security_audit
 * (код, сайт, хто, коли, анонімізований IP; без значень, для секрету — маска). Під списком — «Вхід через Google»
 * (GoogleSignInPanel), «Що виводиться на сайті по містах» (SiteTagResolver: лічильник міста має пріоритет; посилання на
 * місто) та інші інтеграції, які налаштовуються в паку чи на сервері.
 *
 * @extends AbstractCrudController<ServiceSiteSetting>
 */
#[IsGranted('ROLE_ADMIN')]
final class SiteSettingCrudController extends AbstractCrudController
{
    use CancelActionTrait;

    public const string SESSION_SITE = 'admin_settings_site';
    private const string CLEAR_FIELD = 'clearSecret';

    private ?ServiceSite $site = null;

    public function __construct(
        private readonly SiteSettings $settings,
        private readonly SecretBox $box,
        private readonly GoogleSignInPanel $google,
        private readonly SiteTagResolver $tags,
        private readonly ServiceSiteRepository $sites,
        private readonly AdminBranding $branding,
        private readonly LeadCrmConnector $crm,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'monolog.logger.security_audit')]
        private readonly LoggerInterface $audit,
        #[Autowire('%kernel.secret%')]
        private readonly string $kernelSecret = '',
        #[Autowire('%env(STUDIO_API_TOKEN)%')]
        private readonly string $studioToken = '',
        /** @var array{token?: string, cities?: array<string, string>} */
        #[Autowire('%app.gbp_reviews%')]
        private readonly array $gbpReviews = [],
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceSiteSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('setting.label.singular', [], 'admin'))
            ->setEntityLabelInPlural(t('setting.label.plural', [], 'admin'))
            ->setPageTitle(Crud::PAGE_INDEX, fn (): TranslatableMessage => t('setting.title.index', ['%site%' => $this->currentSite()?->getName() ?? '—'], 'admin'))
            ->setPageTitle(Crud::PAGE_EDIT, static fn (ServiceSiteSetting $s): TranslatableMessage => t(SiteSettingKeys::labelKey($s->getCode()), [], 'admin'))
            ->setEntityPermission('ROLE_ADMIN')
            ->setSearchFields(null)
            ->setDefaultSort(['code' => 'ASC'])
            ->setPaginatorPageSize(50)
            ->setHelp(Crud::PAGE_INDEX, t('setting.help.index', [], 'admin'))
            ->overrideTemplate('crud/index', 'admin/setting/index.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $site = Action::new('openSite', t('setting.action.open_site', [], 'admin'), 'fa fa-external-link')
            ->linkToUrl(fn (): string => $this->siteUrl() ?? '#')
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener'])
            ->createAsGlobalAction();

        return $this->addCancelAction($actions)
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $site);
    }

    public function configureFields(string $pageName): iterable
    {
        $tr = $this->translator;
        // назва — посилання на редагування («група → поле» мовою адмінки), технічний ключ — дрібно під ним
        yield TextField::new('code', t('setting.field.label', [], 'admin'))->onlyOnIndex()->setSortable(false)
            ->setTemplatePath('admin/setting/field/label.html.twig')
            ->formatValue(static fn ($v, ?ServiceSiteSetting $s): string => null === $s ? '' : $tr->trans(SiteSettingKeys::groupKey($s->getCode()), [], 'admin') . ' → ' . $tr->trans(SiteSettingKeys::labelKey($s->getCode()), [], 'admin'));
        // значення — текстом (екранування Twig), обрізане; секрет — маска, перемикач — так/ні
        yield TextField::new('valueText', t('setting.field.value', [], 'admin'))->onlyOnIndex()->setSortable(false)
            ->formatValue(static function ($v, ?ServiceSiteSetting $s) use ($tr): string {
                if (null !== $s && SiteSettingKeys::TYPE_BOOL === SiteSettingKeys::type($s->getCode())) {
                    return $tr->trans(\in_array(mb_strtolower(trim((string) $s->getValue())), ['1', 'true', 'yes', 'on'], true) ? 'setting.value.on' : 'setting.value.off', [], 'admin');
                }

                return '' === trim((string) $v) ? '—' : mb_strimwidth((string) $v, 0, 100, '…');
            });
        $settings = $this->settings;
        yield TextField::new('id', t('setting.field.status', [], 'admin'))->onlyOnIndex()->setSortable(false)->renderAsHtml()
            ->setTemplatePath('@EasyAdmin/crud/field/text.html.twig')
            ->formatValue(static function ($v, ?ServiceSiteSetting $s) use ($settings, $tr): string {
                $badge = static fn (string $class, string $key, string $title = ''): string => \sprintf('<span class="badge %s"%s>%s</span>', $class, '' === $title ? '' : ' title="' . htmlspecialchars($title, \ENT_QUOTES) . '"', htmlspecialchars($tr->trans($key, [], 'admin'), \ENT_QUOTES));
                if (null === $s) {
                    return '';
                }
                if (SiteSettingKeys::isSecret($s->getCode())) {
                    $state = $settings->secretState($s->getSite(), $s->getCode());

                    return $badge(match ($state) {
                        SecretState::Ready => 'badge-success',
                        SecretState::Missing => 'badge-secondary',
                        SecretState::NoKey => 'badge-warning',
                        SecretState::Unreadable => 'badge-danger',
                    }, 'setting.secret_state.' . $state->value, $tr->trans('setting.secret_state.hint.' . $state->value, [], 'admin'));
                }
                if (null === $s->getValue() || '' === trim($s->getValue())) {
                    return $badge('badge-secondary', 'setting.status.not_set');
                }

                return SiteSettingKeys::isValidValue($s->getCode(), $s->getValue())
                    ? $badge('badge-success', 'setting.status.set')
                    : $badge('badge-danger', 'setting.status.invalid', $tr->trans('setting.status.invalid_title', [], 'admin'));
            });
        yield DateTimeField::new('updatedAt', t('setting.field.updated_at', [], 'admin'))->onlyOnIndex();
        yield from $this->valueField($pageName);
    }

    /** Лише рядки обраного сайту; відсутні рядки створюються (список завжди повний). */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $site = $this->currentSite();
        if (null === $site) {
            return $qb->andWhere('1 = 0');
        }
        $this->settings->ensureRows($site);

        return $qb->andWhere('entity.site = :site')->setParameter('site', $site);
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $site = $this->currentSite();
        if (Crud::PAGE_INDEX === $responseParameters->get('pageName') && null !== $site) {
            $responseParameters->set('settings_site', $site);
            $responseParameters->set('settings_sites', $this->sites->findBy([], ['id' => 'ASC']));
            $responseParameters->set('google', $this->google->view($site));
            $responseParameters->set('google_edit', $this->editUrls($site, [SiteSettingKeys::GOOGLE_SIGN_IN_ENABLED, SiteSettingKeys::GOOGLE_CLIENT_ID, SiteSettingKeys::GOOGLE_CLIENT_SECRET]));
            $responseParameters->set('city_tags', $this->cityTags($site));
            $responseParameters->set('integrations', [
                'crm' => $this->crm->system(),
                'crm_enabled' => $this->crm->isEnabled(),
                'gbp' => '' !== trim((string) ($this->gbpReviews['token'] ?? '')) || [] !== array_filter((array) ($this->gbpReviews['cities'] ?? [])),
                'studio' => '' !== trim($this->studioToken),
                'key_problem' => $this->box->keyProblem(),
            ]);
        }

        return $responseParameters;
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $secret = SiteSettingKeys::isSecret($entityInstance->getCode());
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $before = \is_string($original['value'] ?? null) ? $original['value'] : null;
        if ($secret && $this->clearRequested()) {
            $entityInstance->setValue(null);
        }
        parent::updateEntity($entityManager, $entityInstance);
        $this->settings->invalidate();
        $this->tags->reset();
        $this->branding->reset();
        if ($before === $entityInstance->getValue()) {
            return;
        }
        $event = $secret ? 'settings.secret_' . (null === $entityInstance->getValue() ? 'cleared' : 'set') : 'settings.changed';
        $user = $this->getUser();
        $ip = $this->getContext()?->getRequest()->getClientIp();
        $this->audit->info($event, [
            'event' => $event,
            'site_id' => $entityInstance->getSite()->getId(),
            'code' => $entityInstance->getCode(),
            'mask' => $secret ? SecretBox::mask($entityInstance->getValue()) : null,
            'user_id' => $user instanceof User ? $user->getId() : null,
            // email — HMAC: журнал порівнюваний, але персональних даних у ньому нема
            'email_hmac' => $user instanceof User ? substr(hash_hmac('sha256', mb_strtolower($user->getUserIdentifier()), $this->kernelSecret), 0, 16) : null,
            'ip' => null === $ip ? null : IpUtils::anonymize($ip),
        ]);
    }

    /**
     * «Перевірити налаштування» входу через Google: формат Client ID, стан секрету, перемикач, адреси міст, discovery/JWKS
     * Google. Результат — flash-список (EasyAdmin рендерить flash як HTML, тому тексти екрануються); значень ключів нема.
     */
    #[AdminRoute(path: '/google-check', name: 'google_check', options: ['methods' => ['POST']])]
    public function googleCheck(Request $request): Response
    {
        $site = $this->currentSite();
        if (!$this->isCsrfTokenValid(GoogleSignInPanel::CSRF, (string) $request->request->get('token', ''))) {
            $this->addFlash('danger', htmlspecialchars($this->translator->trans('settings.google.csrf_invalid', [], 'admin'), \ENT_QUOTES));
        } elseif (null !== $site) {
            $rows = $this->google->check($site);
            $allOk = array_all($rows, static fn (array $row): bool => $row['ok']);
            $items = array_map(static fn (array $row): string => \sprintf('<li class="%s">%s %s</li>', $row['ok'] ? 'text-success' : 'text-danger', $row['ok'] ? '✓' : '✗', htmlspecialchars($row['text'], \ENT_QUOTES)), $rows);
            $this->addFlash($allOk ? 'success' : 'warning', '<ul class="mb-0 ps-3" id="google-check-result">' . implode('', $items) . '</ul>');
        }

        return $this->redirect($this->indexUrl() . '#google-sign-in');
    }

    /** Сайт сторінки: ?site=<id> (запам'ятовується в сесії) → вибраний раніше → сайт адмінки (AdminBranding) → перший. */
    private function currentSite(): ?ServiceSite
    {
        if (null !== $this->site) {
            return $this->site;
        }
        $request = $this->container->get('request_stack')->getCurrentRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;
        $id = (int) ($request?->query->get('site') ?? 0);
        $site = $id > 0 ? $this->sites->find($id) : null;
        if (null !== $site) {
            $session?->set(self::SESSION_SITE, $site->getId());
        } else {
            $remembered = (int) ($session?->get(self::SESSION_SITE) ?? 0);
            $site = $remembered > 0 ? $this->sites->find($remembered) : null;
        }

        return $this->site = $site ?? $this->branding->site() ?? $this->sites->findOneBy([], ['id' => 'ASC']);
    }

    /** Головна апекс-міста сайту для кнопки «Відкрити сайт». */
    private function siteUrl(): ?string
    {
        $site = $this->currentSite();
        if (null === $site) {
            return null;
        }
        $city = $this->container->get('doctrine')->getRepository(ServiceCity::class)->findOneBy(['site' => $site, 'isApex' => true])
            ?? $this->container->get('doctrine')->getRepository(ServiceCity::class)->findOneBy(['site' => $site]);

        return $city instanceof ServiceCity ? 'https://' . $city->getHost() . '/' : null;
    }

    /**
     * Що виводиться на сайті в кожному місті (лічильник міста має пріоритет над GTM сайту) і посилання на поле міста.
     *
     * @return list<array<string, mixed>>
     */
    private function cityTags(ServiceSite $site): array
    {
        $urls = $this->container->get(AdminUrlGeneratorInterface::class);
        $rows = [];
        foreach ($this->container->get('doctrine')->getRepository(ServiceCity::class)->findBy(['site' => $site], ['isApex' => 'DESC', 'id' => 'ASC']) as $city) {
            $t = $this->tags->forCity($city);
            $rows[] = [
                'city' => $city->name('uk'),
                'host' => $city->getHost(),
                'active' => $city->isActive(),
                'cityTag' => $city->getGtmId(),
                'kind' => $t->kind,
                'source' => $t->tagSource,
                'ids' => 'gtm' === $t->kind ? [(string) $t->gtmId] : $t->gtagIds,
                'clarity' => $t->clarityId,
                'claritySource' => $t->claritySource,
                'pixel' => $t->pixelId,
                'consent' => $t->consentBanner,
                'ads' => $t->adsSendTo,
                'edit' => $urls->unsetAll()->setController(ServiceCityCrudController::class)->setAction(Action::EDIT)->setEntityId($city->getId())->generateUrl(),
            ];
        }

        return $rows;
    }

    /**
     * Прямі посилання на редагування рядків (розділ «Вхід через Google»): не шукати рядок у списку.
     *
     * @param list<string> $codes
     *
     * @return array<string, string>
     */
    private function editUrls(ServiceSite $site, array $codes): array
    {
        $this->settings->ensureRows($site);
        $urls = [];
        $generator = $this->container->get(AdminUrlGeneratorInterface::class);
        foreach ($this->container->get('doctrine')->getRepository(ServiceSiteSetting::class)->findBy(['site' => $site, 'code' => $codes]) as $row) {
            $urls[$row->getCode()] = $generator->unsetAll()->setController(self::class)->setAction(Action::EDIT)->setEntityId($row->getId())->generateUrl();
        }

        return $urls;
    }

    private function indexUrl(): string
    {
        return $this->container->get(AdminUrlGeneratorInterface::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl();
    }

    private function clearRequested(): bool
    {
        $form = $this->getContext()?->getRequest()->request->all('ServiceSiteSetting');

        return \is_array($form) && filter_var($form[self::CLEAR_FIELD] ?? false, \FILTER_VALIDATE_BOOL);
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function valueField(string $pageName): iterable
    {
        if (Crud::PAGE_EDIT !== $pageName) {
            return;
        }
        $setting = $this->getContext()?->getEntity()->getInstance();
        $code = $setting instanceof ServiceSiteSetting ? $setting->getCode() : '';
        $label = t(SiteSettingKeys::labelKey($code), [], 'admin');
        $help = t(SiteSettingKeys::helpKey($code), [], 'admin');
        switch (SiteSettingKeys::type($code)) {
            case SiteSettingKeys::TYPE_SECRET:
                yield from $this->secretFields($setting instanceof ServiceSiteSetting ? $setting : null, $label, $help);
                break;
            case SiteSettingKeys::TYPE_BOOL:
                yield BooleanField::new('value', $label)->renderAsSwitch()->setHelp($help)
                    ->setFormTypeOptions([
                        'getter' => static fn (ServiceSiteSetting $s): bool => \in_array(mb_strtolower(trim((string) $s->getValue())), ['1', 'true', 'yes', 'on'], true),
                        'setter' => static fn (ServiceSiteSetting $s, ?bool $v) => $s->setValue($v ? '1' : '0'),
                    ]);
                break;
            case SiteSettingKeys::TYPE_HTML:
                yield TextareaField::new('value', $label)->setHelp(t('setting.help.html_raw', ['%help%' => $this->translator->trans(SiteSettingKeys::helpKey($code), [], 'admin')], 'admin'))->setNumOfRows(12)
                    ->setFormTypeOption('attr', ['spellcheck' => 'false', 'style' => 'font-family: monospace']);
                break;
            default:
                yield TextField::new('value', $label)->setHelp($help)->setRequired(false);
        }
    }

    /**
     * Секрет: поле пароля (завжди порожнє; порожнє при збереженні — лишити чинне), перемикач «видалити» поза мапінгом.
     * Без ключа шифрування — лише пояснення, зберегти нічого не можна.
     *
     * @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface>
     */
    private function secretFields(?ServiceSiteSetting $setting, TranslatableMessage $label, TranslatableMessage $help): iterable
    {
        if (!$this->box->isAvailable()) {
            yield TextField::new('valueText', $label)->setDisabled()->setFormTypeOption('mapped', false)
                ->setHelp(t('setting.help.secret_disabled_' . ('invalid' === $this->box->keyProblem() ? 'invalid' : 'missing'), [], 'admin'));

            return;
        }
        $box = $this->box;
        $state = null === $setting ? SecretState::Missing : $this->settings->secretState($setting->getSite(), $setting->getCode());
        yield TextField::new('value', $label)->setFormType(PasswordType::class)->setRequired(false)
            ->setHelp(SecretState::Unreadable === $state ? t('setting.help.secret_unreadable', [], 'admin') : $help)
            ->setFormTypeOptions([
                'always_empty' => true,
                'attr' => ['autocomplete' => 'new-password', 'placeholder' => SecretBox::mask($setting?->getValue()) ?? $this->translator->trans('setting.status.not_set', [], 'admin')],
                'getter' => static fn (ServiceSiteSetting $s): string => '',
                'setter' => static function (ServiceSiteSetting $s, ?string $v) use ($box): void {
                    $v = trim((string) $v);
                    if ('' !== $v) {
                        $s->setValue($box->encrypt($v));
                    }
                },
            ]);
        yield BooleanField::new(self::CLEAR_FIELD, t('setting.field.clear_secret', [], 'admin'))->renderAsSwitch(false)
            ->setFormTypeOptions(['mapped' => false, 'required' => false])
            ->setHelp(t('setting.help.clear_secret', [], 'admin'));
    }
}
