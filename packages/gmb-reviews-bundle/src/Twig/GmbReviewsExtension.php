<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace Luxsite\GmbReviewsBundle\Twig;

use Luxsite\GmbReviews\Client;
use Luxsite\GmbReviews\Renderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class GmbReviewsExtension extends AbstractExtension
{
    public function __construct(
        private readonly Client $client,
        private readonly ?string $defaultToken,
        private readonly ?\Symfony\Component\HttpFoundation\RequestStack $requestStack = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('gmb_reviews', $this->block(...), ['is_safe' => ['html']]),
            new TwigFunction('gmb_reviews_jsonld', $this->jsonLd(...), ['is_safe' => ['html']]),
            new TwigFunction('gmb_reviews_data', $this->data(...)),
            // загальна оцінка віджета в інших блоках сайту: {value, count, source, …} або null
            new TwigFunction('gmb_rating', $this->rating(...)),
            new TwigFunction('gmb_rating_badge', $this->ratingBadge(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Блок відгуків. $opts: layout, theme, lang, form (bool), schema (bool), css (bool), page_url.
     *
     * @param array<string, mixed> $opts
     */
    public function block(?string $token = null, array $opts = []): string
    {
        $data = $this->data($token);

        // Мова поточного запиту (мультимовний сайт) — для режиму «Авто» в GMB Manager.
        $opts['page_lang'] ??= $this->requestStack?->getCurrentRequest()?->getLocale();

        return null === $data ? '' : Renderer::html($data, $opts);
    }

    public function jsonLd(?string $token = null, ?string $pageUrl = null): string
    {
        $data = $this->data($token);

        if (null === $data) {
            return '';
        }
        // Текст відгуків у розмітці — тією ж мовою, що й у блоці (Renderer::html вирішує мову так само).
        $lang = \Luxsite\GmbReviews\I18n::resolve(null, $data['settings']['lang'] ?? null, $this->requestStack?->getCurrentRequest()?->getLocale());

        return Renderer::jsonLdScript($data, $pageUrl, $lang);
    }

    /**
     * {value, count, source, includes_site, name, maps_url} — та сама оцінка, що в шапці віджета; null — не показуємо
     * / нема даних. Сервер GMB Manager недоступний — з кешу (до 7 діб), інакше null (блок без цифри).
     *
     * @return array<string, mixed>|null
     */
    public function rating(?string $token = null): ?array
    {
        $token ??= $this->defaultToken;

        return null === $token || '' === $token ? null : $this->client->rating($token);
    }

    /**
     * Готовий рядок «5.0 ★★★★★ · 102 відгуки». $opts: css (true), schema (false — розмітку дає віджет або сайт),
     * page_url, link, lang.
     *
     * @param array<string, mixed> $opts
     */
    public function ratingBadge(?string $token = null, array $opts = []): string
    {
        $data = $this->data($token);
        $opts['page_lang'] ??= $this->requestStack?->getCurrentRequest()?->getLocale();

        return null === $data ? '' : Renderer::ratingHtml($data, $opts);
    }

    /** Сирий JSON віджета — для власної верстки. */
    public function data(?string $token = null): ?array
    {
        $token ??= $this->defaultToken;

        return null === $token || '' === $token ? null : $this->client->fetch($token);
    }
}
