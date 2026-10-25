<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceSite;
use App\Settings\AdminBranding;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Count;

#[IsGranted('ROLE_ADMIN')]
class ServiceSiteCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** @param list<string> $locales app.locales — мови розгортання */
    public function __construct(
        private readonly AdminBranding $branding,
        #[Autowire('%app.locales%')]
        private readonly array $locales = ['uk', 'ru'],
        #[Autowire('%app.locale_prefix%')]
        private readonly bool $localePrefix = true,
        #[Autowire('%app.blog_prefix%')]
        private readonly string $blogPrefix = 'blog',
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ServiceSite::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        // сайт у БД один (звичайний прод: БД на сайт) — «Сайт», а не «Сервісні сайти» (власник 21.10.2026)
        $single = $this->branding->singleSite();

        return $crud
            ->setEntityLabelInSingular($single ? 'Сайт' : 'Сервісний сайт')
            ->setEntityLabelInPlural($single ? 'Сайт' : 'Сервісні сайти')
            // Сайти-бренди = конфігурація платформи: тільки адмін
            ->setEntityPermission('ROLE_ADMIN');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addCancelAction($actions);
    }

    public function configureFields(string $pageName): iterable
    {
        // підказки в шапці таблиці беруться з setHelp() самих полів
        yield from $this->withHints($this->fieldList($pageName));
    }

    /** @return iterable<\EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface> */
    private function fieldList(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Назва');
        yield TextField::new('brandNameUk', 'Коротка назва бренду uk')->hideOnIndex()
            ->setHelp('Для текстів на кшталт «Відгуки клієнтів про компанію …». Порожньо — параметр паку app.brand_name, далі «Назва». Загальне слово беріть у лапки: «Електрики».');
        yield TextField::new('brandNameRu', 'Коротка назва бренду ru')->hideOnIndex()
            ->setHelp('Порожньо — як uk.');
        yield TextField::new('slug', 'Slug')
            ->setHelp('Латинський ідентифікатор ніші; dev-хост = <slug>.localhost. Міняти обережно.');
        yield TextField::new('apexDomain', 'Апекс-домен')
            ->setHelp('Домен головного міста, напр. example.com.ua');
        yield TextField::new('schemaType', 'Schema.org тип')
            ->setHelp('Підтип LocalBusiness для JSON-LD: Electrician, Plumber, HouseCleaning…');
        yield BooleanField::new('active', 'Активний');
        yield ChoiceField::new('mode', 'Режим')->hideOnIndex()
            ->setChoices(['Сервісний (каталог, ціни, заявки)' => 'services', 'Контентний (блог, рубрики, форма зв\'язку)' => 'content'])
            ->setHelp('Контентний: головна зі статтями, рубрики, автори, «Про нас», форма зв\'язку; каталог, райони, відгуки, FAQ і калькулятор — 404, контакти міста не публікуються.');
        $locales = ChoiceField::new('locales', 'Мови сайту')->hideOnIndex()
            ->setChoices(array_combine(array_map('strtoupper', $this->locales), $this->locales))
            ->allowMultipleChoices()->renderExpanded();
        if ($this->localePrefix) {
            yield $locales->setHelp(sprintf('Порожньо — усі мови розгортання (%s). Перша мова розгортання — без префікса в адресі; вимкнена мова → 404, без hreflang і sitemap.', implode(', ', $this->locales)));
        } else {
            // app.locale_prefix: false — адреси без мовного префікса, тож мова бренду рівно одна (24.10.2026)
            yield $locales->setHelp('Рівно одна мова: адреси без мовного префікса, мова сайту — ця.')
                ->setFormTypeOption('constraints', [new Count(min: 1, max: 1, exactMessage: 'Оберіть рівно одну мову сайту.')]);
        }
        yield TextField::new('blogPrefix', 'Розділ статей')->hideOnIndex()
            ->setHelp(sprintf('Сегмент адреси статей: /<розділ>/<стаття>/. Порожньо — «%s» (параметр паку app.blog_prefix). Зміна міняє адреси всіх статей бренду — старі без 301.', $this->blogPrefix));
        yield ChoiceField::new('listThumbs', 'Прев\'ю зображень у списках')->hideOnIndex()
            ->setChoices(['Вимкнено' => 'off', 'Лише головна' => 'home', 'Лише стрічка й рубрики (а також теги й автори)' => 'lists', 'Скрізь' => 'all'])
            ->setHelp('Контентний сайт: мініатюра обкладинки біля статті на головній і в списках. Стаття без обкладинки — без мініатюри. Діє одразу, без деплою.');
        yield TextField::new('heroImagePath', 'Ілюстрація хіро')->hideOnIndex()
            ->setHelp('Шлях від кореня сайту, напр. /themes/<ключ-паку>/hero.webp; порожньо = без ілюстрації');

        yield FormField::addFieldset('Сторінка «Про нас»');
        yield TextField::new('aboutTitleUk', 'Заголовок uk')->hideOnIndex();
        yield TextField::new('aboutTitleRu', 'Заголовок ru')->hideOnIndex();
        yield TextEditorField::new('aboutUk', 'Текст uk')->hideOnIndex();
        yield TextEditorField::new('aboutRu', 'Текст ru')->hideOnIndex();

        yield FormField::addFieldset('Юридичні сторінки');
        yield TextEditorField::new('privacyUk', 'Політика конфіденційності uk')->hideOnIndex()
            ->setHelp('Порожньо - на /privacy-policy/ показується типовий текст рушія');
        yield TextEditorField::new('privacyRu', 'Політика конфіденційності ru')->hideOnIndex();
        yield TextEditorField::new('termsUk', 'Умови надання послуг uk')->hideOnIndex()
            ->setHelp('Порожньо - на /terms-of-service/ показується типовий текст рушія');
        yield TextEditorField::new('termsRu', 'Умови надання послуг ru')->hideOnIndex();

        if (\in_array('en', $this->locales, true)) {
            yield FormField::addFieldset('English');
            yield TextField::new('brandNameEn', 'Коротка назва бренду en')->hideOnIndex();
            yield TextField::new('aboutTitleEn', '«Про нас»: заголовок en')->hideOnIndex();
            yield TextEditorField::new('aboutEn', '«Про нас»: текст en')->hideOnIndex()
                ->setHelp('Порожньо — сторінки «Про нас» англійською нема (без фолбеку на uk).');
            yield TextEditorField::new('privacyEn', 'Політика конфіденційності en')->hideOnIndex()
                ->setHelp('Порожньо — типовий текст рушія англійською');
            yield TextEditorField::new('termsEn', 'Умови en')->hideOnIndex()
                ->setHelp('Порожньо — типовий текст рушія англійською');
        }
    }
}
