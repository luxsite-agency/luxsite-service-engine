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
 * Єдине місце, де код знає про схему: шаблони беруть service_path(), контролери — itemParams().
 */
final class CatalogUrls
{
    /**
     * Перші сегменти фіксованих маршрутів сайту (src/Controller/Services, priority 10) і службових префіксів.
     * У схемі root категорія живе на /<slug>/, тому такий slug зробив би сторінку недосяжною (фіксований маршрут
     * виграє) або навпаки; «ru» зловив би /ru/… uk-маршрутом. Сюди ж — префікс блогу (app.blog_prefix).
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
     * Слаги, заборонені для категорії в цій схемі: у root — зарезервовані сегменти (разом із префіксами блогу й команди);
     * у flat/nested категорії живуть під /services/ і ні з чим не стикаються.
     *
     * @return list<string>
     */
    public function reservedCategorySlugs(): array
    {
        if (!$this->isRoot()) {
            return [];
        }

        return array_values(array_unique(array_filter([...self::RESERVED_SEGMENTS, $this->blogPrefix, $this->teamPrefix, CalculatorRoutes::firstSegment($this->calculatorPath)])));
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

    /** Категорії від кореня сайту (/<категорія>/), без /services/. */
    public function isRoot(): bool
    {
        return $this->scheme === 'root';
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
