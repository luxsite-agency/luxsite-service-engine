<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\ServiceSite;

use App\Entity\ServiceItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Параметри маршруту сторінки послуги під схему URL каталогу сайту
 * (app.catalog.url_scheme, маршрути — config/routes/catalog/*.yaml):
 *  - flat:   /services/<послуга>/            → {slug}
 *  - nested: /services/<категорія>/<послуга>/ → {category, slug}
 *  - root:   /<категорія>/<послуга>/          → {category, slug} (як nested, без /services/)
 *  - slug:   /<послуга>/ і /<категорія>/      → {slug} (як flat, від кореня; категорія — окрема сторінка /<категорія>/)
 * Єдине місце, де код знає про схему: шаблони беруть service_path(), контролери — itemParams().
 * Хто ще живе в корені сайту і чи не збігаються слаги — App\ServiceSite\RootSlugs.
 */
final class CatalogUrls
{
    /**
     * Перші сегменти фіксованих маршрутів сайту (src/Controller/Services, priority 10) і службових префіксів.
     * У схемах root і slug категорія (а в slug і послуга, з app.pages — і сторінка) живе на /<slug>/, тому такий slug
     * зробив би сторінку недосяжною (фіксований маршрут виграє) або навпаки; «ru» зловив би /ru/… uk-маршрутом.
     * Сюди ж — префікс блогу (app.blog_prefix, reservedSegments()).
     * Юніт-тест CatalogUrlsTest звіряє список з маршрутами контролерів.
     */
    public const RESERVED_SEGMENTS = [
        'services', 'districts', 'blog', 'news', 'about', 'contacts', 'faq', 'reviews', 'privacy-policy',
        'terms-of-service', 'thank-you', 'calculator-cleaning', 'calculator', 'lead', 'sitemap.xml', 'robots.txt',
        'ru', 'uk', 'api', 'admin', 'account', 'connect', 'login', 'logout', 'media', 'theme-live', 'bundles', '_',
    ];

    public function __construct(
        #[Autowire('%app.catalog.url_scheme%')]
        private readonly string $scheme = 'flat',
        #[Autowire('%app.blog_prefix%')]
        private readonly string $blogPrefix = 'blog',
        #[Autowire('%app.calculator.path%')]
        private readonly mixed $calculatorPath = [],
        #[Autowire('%app.team_prefix%')]
        private readonly string $teamPrefix = '',
    ) {
    }

    public function blogPrefix(): string
    {
        return $this->blogPrefix;
    }

    /**
     * Перші сегменти, зайняті фіксованими розділами сайту: RESERVED_SEGMENTS разом із префіксами блогу й команди паку
     * (app.blog_prefix, app.team_prefix) і першим сегментом адреси калькулятора (app.calculator.path). Їх не може мати ніщо, що живе в корені: категорія
     * (root, slug), послуга (slug), сторінка (app.pages).
     *
     * @return list<string>
     */
    public function reservedSegments(): array
    {
        return array_values(array_unique(array_filter([...self::RESERVED_SEGMENTS, $this->blogPrefix, $this->teamPrefix, CalculatorRoutes::firstSegment($this->calculatorPath)])));
    }

    /**
     * Слаги, заборонені для категорії в цій схемі: у root і slug — зарезервовані сегменти (разом із префіксами блогу й команди);
     * у flat/nested категорії живуть під /services/ і ні з чим не стикаються.
     *
     * @return list<string>
     */
    public function reservedCategorySlugs(): array
    {
        return $this->categoriesAtRoot() ? $this->reservedSegments() : [];
    }

    /** Чи заборонений slug для категорії (reservedCategorySlugs); порівняння без регістру. */
    public function isReservedCategorySlug(?string $slug): bool
    {
        return $slug !== null && $slug !== '' && \in_array(mb_strtolower(trim($slug, '/')), $this->reservedCategorySlugs(), true);
    }

    /** Чи є в URL послуги категорія (nested і root) — і отже сторінки категорій. */
    public function isNested(): bool
    {
        return $this->scheme === 'nested' || $this->scheme === 'root';
    }

    /** Схема root: /<категорія>/<послуга>/ від кореня сайту (сайт з Joomla). */
    public function isRoot(): bool
    {
        return $this->scheme === 'root';
    }

    /** Схема slug: послуга /<послуга>/ і категорія /<категорія>/ від кореня, одним сегментом. */
    public function isSlug(): bool
    {
        return $this->scheme === 'slug';
    }

    /** Чи є в сайту сторінки категорій (nested, root, slug); у flat їх нема. */
    public function hasCategoryPages(): bool
    {
        return $this->scheme !== 'flat';
    }

    /** Категорії від кореня сайту (/<категорія>/), без /services/: root і slug. */
    public function categoriesAtRoot(): bool
    {
        return $this->isRoot() || $this->isSlug();
    }

    /** Послуги від кореня сайту одним сегментом (/<послуга>/): лише slug. */
    public function itemsAtRoot(): bool
    {
        return $this->isSlug();
    }

    /**
     * Слаг послуги зі шляху сторінки (звідки прийшла заявка: CRM бере назву послуги) або null, якщо за схемою це не
     * адреса послуги: flat/nested — останній сегмент після /services/; root — /<категорія>/<послуга>/; slug — /<послуга>/
     * (перший сегмент не фіксований розділ). Префікс /ru/ пропускається. Чи є така послуга — вирішує той, хто питає.
     */
    public function itemSlugFromPath(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        if (($segments[0] ?? null) === 'ru') {
            array_shift($segments);
        }
        if ($this->categoriesAtRoot()) {
            $want = $this->isSlug() ? 1 : 2;
            if (\count($segments) !== $want || \in_array($segments[0], $this->reservedSegments(), true)) {
                return null;
            }

            return $segments[$want - 1];
        }
        $at = array_search('services', $segments, true);

        return $at !== false && isset($segments[$at + 1]) ? (string) end($segments) : null;
    }

    /** @return array<string, string> параметри для generateUrl('sv_service', …) у заданій мові */
    public function itemParams(ServiceItem $item, string $locale): array
    {
        $params = ['slug' => $item->slug($locale)];
        if ($this->isNested()) {
            $params['category'] = $item->getCategory()->slug($locale);
        }

        return $params;
    }
}
