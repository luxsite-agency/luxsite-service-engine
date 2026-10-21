<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceReview;
use App\Security\GoogleOAuthConfig;
use App\Settings\AdminBranding;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_MODERATOR')]
class ServiceReviewCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;
    use ImageFieldsTrait;

    /** У списку виноситься наперед: автор відгуку, а не місто */
    private const INDEX_PRIMARY = 'authorUk';

    public function __construct(
        private readonly GoogleOAuthConfig $googleOAuth,
        private readonly AdminBranding $branding,
        private readonly AdminUrlGeneratorInterface $adminUrls,
        private readonly TranslatorInterface $translator,
        #[Autowire('%app.reviews.require_location%')]
        private readonly bool $requireLocation = false,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceReview::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // без ключів Google відвідувачі не можуть залишити відгук — кажемо тут, а не на сайті; сайт — той, якому належить
        // адмінка (AdminBranding), ключі вводяться в «Налаштування сайту» → «Вхід через Google»
        $site = $this->branding->site();
        if (!$this->googleOAuth->isConfigured($site)) {
            $crud->setHelp(Crud::PAGE_INDEX, '⚠ ' . htmlspecialchars($this->translator->trans('dashboard.google_warning', ['%site%' => ''], 'admin'), \ENT_QUOTES)
                . ($this->isGranted('ROLE_ADMIN') ? ' <a href="' . htmlspecialchars($this->adminUrls->unsetAll()->setController(SiteSettingCrudController::class)->setAction('index')->generateUrl(), \ENT_QUOTES) . '#google-sign-in">' . htmlspecialchars($this->translator->trans('dashboard.google_link', [], 'admin'), \ENT_QUOTES) . '</a>' : ''));
        }

        return $crud
            ->setEntityLabelInSingular('Відгук клієнта')
            ->setEntityLabelInPlural('Відгуки клієнтів')
            ->setDefaultSort(['reviewedAt' => 'DESC'])
            ->setEntityPermission('ROLE_MODERATOR');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        // підказки в шапці таблиці беруться з setHelp() самих полів
        yield from $this->withHints($this->primaryFirst($this->fieldList($pageName), $pageName));
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('city', 'Місто')
            ->setHelp('Відгук показується на сайті цього міста.');
        yield TextField::new('authorUk', 'Автор uk')
            ->setHelp('Тільки імʼя, без прізвища - так домовились із партнером.');
        yield TextField::new('authorRu', 'Автор ru')->hideOnIndex()
            ->setHelp('Порожньо = показується українське імʼя');
        yield TextField::new('location', 'Місто')
            ->setRequired($this->requireLocation)
            ->setHelp('Місто автора, як він вказав («Київ»). Показується на картці поряд з імʼям.');
        yield IntegerField::new('stars', 'Зірки')
            ->setHelp('1–5');
        yield DateField::new('reviewedAt', 'Дата відгуку')
            ->setHelp('Дата, яку видно під відгуком. Відгуки сортуються від нових до старих.');
        yield TextareaField::new('textUk', 'Текст uk')->hideOnIndex()
            ->setHelp('Текст живої людини. ⚠️ Не переписувати і не «покращувати» - це чужі слова.');
        yield TextareaField::new('textRu', 'Текст ru')->hideOnIndex()
            ->setHelp('Порожньо - показується український текст.');
        yield from $this->imageFields('/media/service_review');
        yield BooleanField::new('active', 'Активний')
            ->setHelp('Вимкнений відгук зникає зі стрічки на головній і зі сторінки відгуків.');
    }
}
