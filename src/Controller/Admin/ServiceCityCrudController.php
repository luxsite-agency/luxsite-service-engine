<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ServiceCity;
use App\Form\ServiceFaqItemType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ServiceCityCrudController extends AbstractCrudController
{
    use CancelActionTrait;
    use FieldHintTrait;

    /** У списку виноситься наперед: назва міста, а не хост */
    private const INDEX_PRIMARY = 'nameNomUk';

    public static function getEntityFqcn(): string
    {
        return ServiceCity::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Місто сервісу')
            ->setEntityLabelInPlural('Міста сервісів')
            ->setDefaultSort(['site' => 'ASC', 'isApex' => 'DESC'])
            ->setEntityPermission('ROLE_ADMIN');
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

        yield FormField::addTab('Основне', 'fa fa-gear');
        yield AssociationField::new('site', 'Сайт')->setColumns(6);
        yield TextField::new('host', 'Хост')->setColumns(6)
            ->setHelp('Прод-хост: апекс (example.com.ua), піддомен (kharkiv.example.com.ua) '
                . 'або окремий домен міста');
        yield TextField::new('slug', 'Slug')->setColumns(6)
            ->setHelp('Dev-хост = <slug>.<site>.localhost. Міняти обережно.');
        yield FormField::addRow();
        yield BooleanField::new('isApex', 'Апекс')->setColumns(6)
            ->setHelp('Головне місто бренду; живе на апекс-домені й dev-хості без піддомена');
        yield BooleanField::new('active', 'Активне')->setColumns(6);

        yield FormField::addFieldset('Аналітика та індексація');
        yield BooleanField::new('noindex', 'Місто в розробці (noindex)')->setColumns(6)
            ->setHelp('Закриває місто від пошуковиків: meta robots, robots.txt і карта сайту');
        yield TextField::new('gtmId', 'Лічильник аналітики')->hideOnIndex()->setColumns(6)
            ->setHelp(
                'Вставте сюди код, який дав Google - цілком, разом зі script. '
                . 'Ідентифікатор витягнеться сам і стане куди треба: GTM-… у head і body, '
                . 'G-… або AW-… окремим тегом у head. Можна вставити й сам ідентифікатор. '
                . 'Порожньо - на сайті цього міста лічильника не буде.'
            );

        yield FormField::addTab('Контакти', 'fa fa-address-book');
        yield TextField::new('phone', 'Телефон')->hideOnIndex()->setColumns(6)
            ->setHelp(
                'Номер у шапці й у кнопках дзвінка. Кліки по ньому рахуються в аналітиці як подія '
                . 'phone_click.'
            );
        yield TextField::new('email', 'Публічний email')->hideOnIndex()->setColumns(6)
            ->setHelp('Показується у футері та на сторінці контактів');
        yield TextField::new('viber', 'Viber')->hideOnIndex()->setColumns(4)
            ->setHelp('Номер у міжнародному форматі: 380969293247 (можна з +, пробілами — зайве приберемо). Порожньо = кнопки нема. Кліки — подія messenger_click.');
        yield TextField::new('telegram', 'Telegram')->hideOnIndex()->setColumns(4)
            ->setHelp('Username без @ (або посилання t.me/…). Порожньо = кнопки нема.');
        yield TextField::new('whatsapp', 'WhatsApp')->hideOnIndex()->setColumns(4)
            ->setHelp('Номер у міжнародному форматі: 380969293247. Порожньо = кнопки нема.');
        yield TextField::new('leadEmails', 'Пересилка заявок')->hideOnIndex()->setColumns(12)
            ->setHelp('Адреси через кому; порожньо = заявки йдуть на публічний email');
        yield TextField::new('hours', 'Години роботи')->hideOnIndex()->setColumns(6)
            ->setHelp('Графік, як його видно на сайті: «Пн-Нд, 9:00-21:00».');
        yield TextareaField::new('addressUk', 'Адреса (uk)')->hideOnIndex()->setColumns(6)
            ->setHelp('Показується у футері. Кілька адрес - кожна з нового рядка; у розмітку Google іде лише перший рядок. Порожньо - адреси на сайті немає.');
        yield TextareaField::new('addressRu', 'Адреса (ru)')->hideOnIndex()->setColumns(6)
            ->setHelp('Порожньо - на ru-сторінках буде українська.');
        yield TextareaField::new('aboutWhereUk', '«Про нас»: де працюємо (uk)')->hideOnIndex()->setColumns(6)
            ->setHelp('Абзац під заголовком «Де ми працюємо» на сторінці «Про нас»: райони й передмістя цього міста. Порожньо - розділу не буде.');
        yield TextareaField::new('aboutWhereRu', '«Про нас»: де працюємо (ru)')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('aboutUk', '«Про нас» для міста (uk)')->hideOnIndex()->setColumns(6)
            ->setHelp('Повний текст сторінки «Про нас» лише для цього міста (можна з маркером <!--city-where-->). Порожньо = спільний текст бренду.');
        yield TextEditorField::new('aboutRu', '«Про нас» для міста (ru)')->hideOnIndex()->setColumns(6);
        yield TextField::new('mapEmbedUrl', 'Карта: посилання для вставки')->hideOnIndex()->setColumns(12)
            ->setHelp('Google Maps: «Поділитися → Вставити карту», скопіювати адресу з src="…". Порожньо - блок карти на контактах не показуємо');
        yield TextField::new('mapLinkUrl', 'Карта: посилання на листинг')->hideOnIndex()->setColumns(12)
            ->setHelp('Куди веде кнопка «Відкрити на Google Картах»: посилання з кнопки «Поділитися» у картці компанії');
        yield FormField::addTab('Назва міста', 'fa fa-spell-check');
        yield FormField::addFieldset('Відмінки: українська зліва, російська справа');
        yield TextField::new('nameNomUk', 'Називний uk')->setColumns(6)
            ->setHelp('Називний відмінок: «Київ». Використовується в назвах і перемикачі міст.');
        yield TextField::new('nameNomRu', 'Називний ru')->hideOnIndex()->setColumns(6);
        yield TextField::new('nameGenUk', 'Родовий uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Родовий: «Києва» - «райони Києва».');
        yield TextField::new('nameGenRu', 'Родовий ru')->hideOnIndex()->setColumns(6);
        yield TextField::new('nameDatUk', 'Давальний uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Давальний: «Києву».');
        yield TextField::new('nameDatRu', 'Давальний ru')->hideOnIndex()->setColumns(6);
        yield TextField::new('nameLocUk', 'Місцевий uk')->hideOnIndex()->setColumns(6)
            ->setHelp('«у Києві» - без прийменника');
        yield TextField::new('nameLocRu', 'Місцевий ru')->hideOnIndex()->setColumns(6);

        yield FormField::addTab('Тексти головної', 'fa fa-align-left');
        yield TextField::new('introTitleUk', 'H1 uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Заголовок H1 головної сторінки міста.');
        yield TextField::new('introTitleRu', 'H1 ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('introTextUk', 'Інтро uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Текст під заголовком на головній.');
        yield TextEditorField::new('introTextRu', 'Інтро ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('text2Uk', 'Блок 2 uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Другий блок тексту головної - під переліком послуг.');
        yield TextEditorField::new('text2Ru', 'Блок 2 ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('text3Uk', 'Блок 3 uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Третій блок - під таблицею цін.');
        yield TextEditorField::new('text3Ru', 'Блок 3 ru')->hideOnIndex()->setColumns(6);
        yield TextEditorField::new('districtsIntroUk', 'Інтро районів uk')->hideOnIndex()->setColumns(6)
            ->setHelp('Вступний текст на сторінці переліку районів.');
        yield TextEditorField::new('districtsIntroRu', 'Інтро районів ru')->hideOnIndex()->setColumns(6);

        yield FormField::addTab('FAQ', 'fa fa-circle-question');
        yield CollectionField::new('faqUk', 'FAQ uk')->hideOnIndex()->setColumns(6)
            ->setEntryType(ServiceFaqItemType::class)
            ->allowAdd()->allowDelete()
            ->setHelp('Питання і відповіді на головній. Потрапляють у мікророзмітку FAQ.');
        yield CollectionField::new('faqRu', 'FAQ ru')->hideOnIndex()->setColumns(6)
            ->setEntryType(ServiceFaqItemType::class)
            ->allowAdd()->allowDelete()
            ->setHelp('Порядок пунктів у ru має збігатися з uk');

        yield FormField::addTab('SEO', 'fa fa-magnifying-glass');
        yield TextField::new('metaTitleUk', 'Meta title uk')->hideOnIndex()->setColumns(6)
            ->setHelp('До ~60 символів');
        yield TextField::new('metaTitleRu', 'Meta title ru')->hideOnIndex()->setColumns(6);
        yield TextareaField::new('metaDescUk', 'Meta description uk')->hideOnIndex()->setColumns(6)
            ->setHelp('~150-160 символів');
        yield TextareaField::new('metaDescRu', 'Meta description ru')->hideOnIndex()->setColumns(6);
    }
}
