<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ServiceItem;
use App\Entity\ServicePostCategory;
use App\Entity\ServiceSite;
use App\Entity\ServiceCity;
use App\Repository\ServiceCalcTypeRepository;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceItemCityRepository;
use App\Repository\ServiceItemRepository;
use App\Repository\ServicePageRepository;
use App\Repository\ServicePostCategoryRepository;
use App\Repository\ServicePostRepository;
use App\ServiceSite\CatalogUrls;
use App\ServiceSite\CustomFields;
use App\ServiceSite\SiteLocales;
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
        private readonly ?ServicePageRepository $pages = null,
        #[Autowire('%app.pages%')]
        private readonly bool $pagesEnabled = false,
        private readonly ?SiteLocales $siteLocales = null,
        private readonly ?ServicePostCategoryRepository $postCategories = null,
    ) {
    }

    /** @var array<string, list<ServicePostCategory>> рубрики бренду на запит: «<id сайту>:<мова>» → список */
    private array $categoryCache = [];
    /** @var array<string, array<int, int>> кількість статей у рубриках: «<id сайту>:<мова>» → [id рубрики → кількість] */
    private array $categoryCountCache = [];

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
            new TwigFunction('footer_pages', $this->footerPages(...)),
            new TwigFunction('site_brand', $this->siteBrand(...)),
            new TwigFunction('reviews_title_city', $this->reviewsTitleCity(...)),
            new TwigFunction('page_meta', $this->pageMeta(...)),
            new TwigFunction('custom_fields', $this->customFieldsOf(...)),
            // мови розгортання й бренду (app.locales, ServiceSite.locales; 22.10.2026) — замість зашитих uk/ru
            new TwigFunction('sv_locale', fn (): string => $this->locales()->current()),
            new TwigFunction('sv_locales', fn (?ServiceSite $site = null): array => $this->locales()->enabled($site)),
            new TwigFunction('sv_default_locale', fn (): string => $this->locales()->default()),
            new TwigFunction('sv_locale_code', fn (string $locale, string $kind): string => $this->locales()->code($locale, $kind)),
            new TwigFunction('sv_path_locale', fn (): string => $this->locales()->fromPath()),
            // дата мовою сторінки (intl): «22 жовт. 2026 р.», «Oct 22, 2026» — для статей контентного сайту
            new TwigFunction('sv_date', static fn (?\DateTimeInterface $date, string $locale): string => $date === null ? ''
                : (string) (new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE))->format($date)),
            // каркас сторінки за режимом бренду: контентний сайт — services/content/layout.html.twig, інакше — services/base.html.twig
            // рубрики бренду (активні, мовою сторінки, за позицією) — для шапки й футера на будь-якій сторінці, не лише там,
            // де контролер передає categories; без бренду чи на сервісному сайті без рубрик — []
            // sv_categories(true) — лише рубрики, де є опубліковані статті мовою сторінки (меню новинного сайту без порожніх)
            new TwigFunction('sv_categories', $this->navCategories(...)),
            // блоки рубрик на головній новинного сайту: $limit найбільших рубрик бренду (за кількістю статей мовою сторінки)
            // по $perCategory свіжих статей — [{category, posts, count}]; порожні рубрики пропускаються
            new TwigFunction('sv_category_posts', $this->categoryPosts(...)),
            // мініатюри статей у списках (ServiceSite.listThumbs, адмінка → бренд, 25.10.2026): sv_thumbs('home') — головна,
            // sv_thumbs('lists') — стрічка, рубрика, тег, автор; без бренду — false
            new TwigFunction('sv_thumbs', fn (string $where): bool => (bool) $this->locales()->currentSite()?->showsThumbs($where)),
            new TwigFunction('sv_layout', fn (): string => $this->locales()->currentSite()?->isContent() ? 'services/content/layout.html.twig' : 'services/base.html.twig'),
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

    /** @var array<int, list<\App\Entity\ServicePage>> memo: id міста → сторінки футера (smoke ганяє кілька хостів одним ядром) */
    private array $footerPages = [];

    /**
     * Сторінки app.pages з позначкою «У футері», видимі в поточному місті, у порядку position; app.pages: false — [].
     *
     * @return list<\App\Entity\ServicePage>
     */
    public function footerPages(ServiceSite $site): array
    {
        $city = $this->requests->getCurrentRequest()?->attributes->get('service_city');
        if (!$this->pagesEnabled || $this->pages === null || !$city instanceof ServiceCity) {
            return [];
        }

        return $this->footerPages[(int) $city->getId()] ??= array_values(array_filter(
            $this->pages->findLiveBySite($site, $city),
            static fn (\App\Entity\ServicePage $p): bool => $p->isInFooter(),
        ));
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
        return $this->hasBlog[$site->getId()] ??= $this->posts->countPublishedBySite($site, $this->locales()->current()) > 0;
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
        $value = $this->pageMeta[$route][$field . '_' . $this->locales()->current()] ?? null;

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** Шлях сторінки послуги під схему URL каталогу сайту (flat, nested чи root) — замість path('sv_service'). */
    public function servicePath(ServiceItem $item, string $locale): string
    {
        return $this->urls->generate('sv_service', $this->catalogUrls->itemParams($item, $locale) + ['_locale' => $locale]);
    }

    /** @return list<array{category: ServicePostCategory, posts: list<\App\Entity\ServicePost>, count: int}> */
    public function categoryPosts(int $limit = 4, int $perCategory = 5): array
    {
        $site = $this->locales()->currentSite();
        if ($site === null || $site->getId() === null || $limit < 1 || $perCategory < 1) {
            return [];
        }
        $locale = $this->locales()->current();
        $counts = $this->categoryCounts($site, $locale);
        $cats = array_values(array_filter($this->brandCategories($site), static fn (ServicePostCategory $c): bool => ($counts[(int) $c->getId()] ?? 0) > 0));
        // більші рубрики першими; при рівній кількості — порядок рубрик бренду (позиція)
        $order = array_flip(array_map(static fn (ServicePostCategory $c): int => (int) $c->getId(), $cats));
        usort($cats, static fn (ServicePostCategory $a, ServicePostCategory $b): int => [$counts[(int) $b->getId()], $order[(int) $a->getId()]] <=> [$counts[(int) $a->getId()], $order[(int) $b->getId()]]);

        $out = [];
        foreach (\array_slice($cats, 0, $limit) as $c) {
            $out[] = [
                'category' => $c,
                'posts' => $this->posts->findPublishedFiltered($site, $locale, $c, null, 1, $perCategory),
                'count' => $counts[(int) $c->getId()],
            ];
        }

        return $out;
    }

    /** @return list<ServicePostCategory> */
    public function navCategories(bool $withPosts = false): array
    {
        $cats = $this->brandCategories();
        $site = $this->locales()->currentSite();
        if (!$withPosts || $site === null || $site->getId() === null) {
            return $cats;
        }
        $counts = $this->categoryCounts($site, $this->locales()->current());

        return array_values(array_filter($cats, static fn (ServicePostCategory $c): bool => ($counts[(int) $c->getId()] ?? 0) > 0));
    }

    /** @return array<int, int> id рубрики → опубліковані статті (один запит на сторінку: меню, футер і блоки головної) */
    private function categoryCounts(ServiceSite $site, string $locale): array
    {
        return $this->categoryCountCache[$site->getId() . ':' . $locale] ??= $this->posts->countPublishedByCategory($site, $locale);
    }

    /** @return list<ServicePostCategory> */
    public function brandCategories(?ServiceSite $site = null): array
    {
        $site ??= $this->locales()->currentSite();
        if ($site === null || $this->postCategories === null || $site->getId() === null) {
            return [];
        }
        $locale = $this->locales()->current();

        return $this->categoryCache[$site->getId() . ':' . $locale] ??= $this->postCategories->findActiveBySite($site, $locale);
    }

    private function locales(): SiteLocales
    {
        return $this->siteLocales ?? new SiteLocales($this->requests);
    }
}
