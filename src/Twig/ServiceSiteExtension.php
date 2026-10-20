<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ServiceItem;
use App\Entity\ServiceSite;
use App\Entity\ServiceCity;
use App\Repository\ServiceCalcTypeRepository;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceItemCityRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePostRepository;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\CustomFields;
use App\ServiceSite\LeadConsent;
use App\ServiceSite\LeadFields;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Бренди платформи різні: сайт працює на послуги, тож блог тут не напрям:
 * порожній розділ не повинен світитись ні в меню, ні в
 * карті сайту, тож наявність блогу перевіряється по факту публікацій.
 */
final class ServiceSiteExtension extends AbstractExtension
{
    /** @var array<int, bool> memo на запит: меню рендериться двічі (шапка + підвал) */
    private array $hasBlog = [];

    /** @var array<int, bool> те саме для калькулятора */
    private array $hasCalculator = [];

    /** @var array<int, int> memo на запит: активних міст сайту (title і H1 відгуків) */
    private array $activeCityCount = [];

    public function __construct(
        private readonly ServicePostRepository $posts,
        private readonly ServiceCityRepository $cities,
        private readonly ServiceCalcTypeRepository $calcTypes,
        private readonly CatalogUrls $catalogUrls,
        private readonly UrlGeneratorInterface $urls,
        private readonly ServiceItemCityRepository $itemTexts,
        private readonly RequestStack $requests,
        private readonly ServiceItemRepository $items,
        /** @var array<string, string> app.brand_name: {uk: …, ru: …} */
        #[Autowire('%app.brand_name%')]
        private readonly array $brandNames = [],
        /** app.reviews.title_city: auto | always | never */
        #[Autowire('%app.reviews.title_city%')]
        private readonly string $reviewsTitleCity = 'auto',
        /** @var array<string, array<string, string>> app.page_meta: маршрут → {title_uk, title_ru, desc_uk, desc_ru} */
        #[Autowire('%app.page_meta%')]
        private readonly array $pageMeta = [],
        private readonly ?CustomFields $customFields = null,
        private readonly ?LeadFields $leadFields = null,
        private readonly ?LeadConsent $leadConsent = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_has_blog', $this->siteHasBlog(...)),
            new TwigFunction('site_has_calculator', $this->siteHasCalculator(...)),
            new TwigFunction('service_path', $this->servicePath(...)),
            new TwigFunction('city_switcher', $this->citySwitcher(...)),
            new TwigFunction('city_in', $this->cityIn(...)),
            new TwigFunction('ov', $this->ov(...)),
            new TwigFunction('item_text', $this->itemText(...)),
            new TwigFunction('footer_catalog', $this->footerCatalog(...)),
            new TwigFunction('site_brand', $this->siteBrand(...)),
            new TwigFunction('reviews_title_city', $this->reviewsTitleCity(...)),
            new TwigFunction('page_meta', $this->pageMeta(...)),
            new TwigFunction('custom_fields', $this->customFieldsOf(...)),
            new TwigFunction('lead_fields', $this->leadFieldsFor(...)),
            new TwigFunction('lead_consent', $this->leadConsentFor(...)),
        ];
    }

    /**
     * Чи додавати місто в title/H1 сторінки відгуків (app.reviews.title_city, власник 18.10.2026): always / never;
     * auto (і будь-яке інше значення) — так, якщо в сайту більше одного активного міста або поточне місто не апекс
     * (піддомен міста), інакше заголовки міст збігаються. Сайт одного міста на апексі — без міста.
     */
    public function reviewsTitleCity(ServiceSite $site, ServiceCity $city): bool
    {
        return match ($this->reviewsTitleCity) {
            'always' => true,
            'never' => false,
            default => !$city->isApex() || ($this->activeCityCount[(int) $site->getId()] ??= \count($this->cities->findAllActiveBySite($site))) > 1,
        };
    }

    /**
     * Коротка назва бренду мовою сторінки (власник 18.10.2026, «щоб автоматично підтягувало»): поле сайту в адмінці →
     * параметр паку app.brand_name {uk, ru} → назва сайту. Для «Відгуки клієнтів про компанію %site%» тощо.
     */
    public function siteBrand(ServiceSite $site, string $locale): string
    {
        $fromPack = (string) ($this->brandNames[$locale] ?? $this->brandNames['uk'] ?? '');

        return $site->brandName($locale) ?? ('' !== trim($fromPack) ? trim($fromPack) : $site->getName());
    }

    /**
     * Каталог для футера (app.footer_catalog): активні категорії сайту в порядку каталогу, у кожній — її активні послуги.
     * Один запит (ServiceItemRepository::findActiveBySite з join категорії); категорії без послуг не показуються.
     *
     * @return list<array{category: \App\Entity\ServiceCategory, items: list<ServiceItem>}>
     */
    public function footerCatalog(ServiceSite $site): array
    {
        $groups = [];
        $city = $this->requests->getCurrentRequest()?->attributes->get('service_city');
        foreach ($this->items->findActiveBySite($site, $city instanceof ServiceCity ? $city : null) as $item) {
            $cid = (int) $item->getCategory()->getId();
            $groups[$cid] ??= ['category' => $item->getCategory(), 'items' => []];
            $groups[$cid]['items'][] = $item;
        }

        return array_values($groups);
    }

    /** @var array<int, array<int, \App\Entity\ServiceItemCity>> кеш перевизначень на запит: id міста → (id послуги → тексти) */
    private array $cityItemTexts = [];

    /**
     * Текст послуги для поточного міста (атрибут запиту service_city): перевизначення ServiceItemCity, якщо є і поле
     * заповнене, інакше поле послуги. Для списків (картки в /services/, категорії, на головній, «інші послуги»),
     * де контролер не тягне override на кожну послугу: мапа міста вантажиться одним запитом і кешується на запит.
     */
    public function itemText(ServiceItem $item, string $property, string $locale): mixed
    {
        $city = $this->requests->getCurrentRequest()?->attributes->get('service_city');
        if ($city instanceof ServiceCity) {
            $cityId = (int) $city->getId();
            $this->cityItemTexts[$cityId] ??= $this->itemTexts->findByCityIndexed($city);
            $override = $this->cityItemTexts[$cityId][(int) $item->getId()] ?? null;
            if ($override !== null) {
                return $this->ov($override, $item, $property, $locale);
            }
        }

        return $item->loc($property, $locale);
    }

    /**
     * Значення поля з міського перевизначення (ServiceItemCity/ServiceCategoryCity) або з базової сутності:
     * ov(override, item, 'desc2', locale). Порожнє в перевизначенні (null/''/[]) = беремо базове.
     */
    public function ov(?object $override, object $base, string $property, string $locale): mixed
    {
        // Ланцюжок для ru: override.ru → base.ru → override.uk → base.uk (у полів із фолбеком). Раніше override->loc()
        // сам падав ru→uk усередині перевизначення, і /ru/ отримував українську назву міста замість base.ru
        // (типовий випадок: title_uk заповнено, title_ru ні → українська H1 на /ru/).
        $filled = static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== [];
        $hasOverride = $override !== null && method_exists($override, 'locRaw');
        if ($hasOverride && $filled($v = $override->locRaw($property, $locale))) {
            return $v;
        }
        if (method_exists($base, 'locRaw') && $filled($v = $base->locRaw($property, $locale))) {
            return $v;
        }
        if ($locale !== 'uk' && $hasOverride && $override->locFallsBack($property) && $filled($v = $override->locRaw($property, 'uk'))) {
            return $v;
        }

        return method_exists($base, 'loc') ? $base->loc($property, $locale) : null;
    }

    /**
     * Прийменник перед назвою міста (місцевий відмінок nameLoc).
     * Українською: перед голосною «в» («в Одесі»); перед в/ф і збігами льв/св/тв/хв/зв/дв — «у» («у Вінниці», «у Львові»);
     * перед іншою приголосною — «у» («у Києві»), але якщо передано $after (текст перед прийменником) і він закінчується
     * голосною, то «в» («про компанію «Майстри» в Чернівцях»; лапки й пробіли в кінці не враховуються).
     * Російською «в», перед «Вл/Вр/Льв…» — «во» («во Львове»).
     */
    public function cityIn(ServiceCity $city, string $locale, ?string $after = null): string
    {
        $name = trim((string) ($city->loc('nameLoc', $locale) ?? $city->name($locale)));
        if ($locale !== 'uk') {
            return preg_match('/^(В[^аеёиоуыэюяАЕЁИОУЫЭЮЯ]|Ф[^аеёиоуыэюя]|Льв|Мн)/u', $name) === 1 ? 'во' : 'в';
        }

        $name = trim((string) $city->loc('nameLoc', 'uk')) ?: $name;
        if (preg_match('/^[АЕЄИІЇОУЮЯ]/u', $name) === 1) {
            return 'в';
        }
        if (preg_match('/^([ВФ]|Льв|Св|Тв|Хв|Зв|Дв)/u', $name) === 1) {
            return 'у';
        }
        $tail = mb_strtolower(mb_substr((string) preg_replace('/[\s«»"\'„“”’.,:]+$/u', '', (string) $after), -1));

        return $tail !== '' && mb_strpos('аеєиіїоуюяaeiouy', $tail) !== false ? 'в' : 'у';
    }

    /**
     * Міста для перемикача в шапці: усі активні, включно з тими, що ще в
     * розробці. Посилання на закриті міста шаблон віддає з rel="nofollow" -
     * самі сторінки під noindex, тож в індекс вони не потраплять.
     *
     * @return ServiceCity[]
     */
    public function citySwitcher(ServiceSite $site, ServiceCity $current): array
    {
        return $this->cities->findAllActiveBySite($site);
    }

    public function siteHasBlog(ServiceSite $site): bool
    {
        return $this->hasBlog[$site->getId()] ??= $this->posts->countPublishedBySite($site) > 0;
    }

    /**
     * Калькулятор — функція рушія, але наповнений він не в кожного бренду: без типів у БД пункт меню й посилання на сторінку не показуються.
     */
    public function siteHasCalculator(ServiceSite $site): bool
    {
        return $this->hasCalculator[$site->getId()] ??= $this->calcTypes->findActiveBySite($site) !== [];
    }

    /**
     * Заповнені додаткові поля запису (app.custom_fields паку) для показу: [{key, label, value, raw, filter}].
     *
     * @return list<array{key: string, label: string, value: string, raw: string, filter: bool}>
     */
    public function customFieldsOf(object $entity, string $locale): array
    {
        return $this->customFields?->display($entity, $locale) ?? [];
    }

    /**
     * Додаткові поля форми заявки з паку (app.lead_fields) мовою сторінки, варіанти select — для міста запиту; [] — форма
     * як була. $preset — заздалегідь обраний варіант за джерелом: {services: item.slugUk} на сторінці послуги.
     *
     * @param array<string, string> $preset
     *
     * @return list<array<string, mixed>>
     */
    public function leadFieldsFor(string $locale, array $preset = []): array
    {
        if ($this->leadFields === null || !$this->leadFields->enabled()) {
            return [];
        }
        $city = $this->requests->getCurrentRequest()?->attributes->get('service_city');

        return $this->leadFields->forForm($locale, $city instanceof ServiceCity ? $city : null, $preset);
    }

    /**
     * Чекбокс згоди на обробку персональних даних (app.lead_consent): {text, policy_text, policy_url} мовою сторінки
     * (порожнє — дефолт рушія в шаблоні); null — вимкнено.
     *
     * @return array{text: string, policy_text: string, policy_url: string}|null
     */
    public function leadConsentFor(string $locale): ?array
    {
        return $this->leadConsent?->forForm($locale);
    }

    /**
     * SEO-перевизначення фіксованої сторінки з паку (app.page_meta): title/desc поточного маршруту мовою сторінки —
     * для сторінок, чий заголовок рушій будує з перекладів (/about/, /news/, /privacy-policy/ …), коли після міграції
     * треба зберегти старий <title> 1:1. Ключ — назва маршруту без локалі (sv_blog). null = як у шаблоні.
     */
    public function pageMeta(string $field): ?string
    {
        $request = $this->requests->getCurrentRequest();
        if ($request === null || $this->pageMeta === []) {
            return null;
        }
        $route = (string) ($request->attributes->get('_canonical_route') ?? $request->attributes->get('_route') ?? '');
        $value = $this->pageMeta[$route][$field . '_' . ($request->getLocale() === 'ru' ? 'ru' : 'uk')] ?? null;

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Шлях сторінки послуги під схему URL каталогу сайту (flat, nested чи root) — замість path('sv_service'). */
    public function servicePath(ServiceItem $item, string $locale): string
    {
        return $this->urls->generate('sv_service', $this->catalogUrls->itemParams($item, $locale) + ['_locale' => $locale]);
    }
}
