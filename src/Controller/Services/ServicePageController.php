<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Services;

use App\Repository\ServiceItemRepository;
use App\Repository\ServicePageRepository;
use App\ServiceSite\HtmlSitemap;
use App\ServiceSite\RootSlugs;
use App\ServiceSite\ServiceCityAware;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Сторінки app.pages (ServicePage) від кореня: /<slug>/ і /<батько>/<slug>/, ru — /ru/…, без префікса /page/.
 * Маршрут ловить будь-який шлях на один-два сегменти, але спрацьовує лише для наявної активної сторінки (умова
 * root_slugs, App\ServiceSite\RootSlugs): інакше роутер іде далі — до каталогу (root/slug), legacy-301 чи 404.
 * Пріоритет 5: після фіксованих сторінок (10), перед каталогом (0). З app.pages: false умова завжди хибна.
 * Шаблон сторінки (ServicePage::template): page — текст; hub — ще й картки обраних послуг (hubItems, порядок каталогу,
 * лише видимі в місті); sitemap — ще й HTML-карта (HtmlSitemap); form:<ключ> — ще й форма services/page/form/<ключ>.
 * noindex сторінки або батька — meta robots і X-Robots-Tag «noindex, follow».
 */
final class ServicePageController extends AbstractController
{
    use ServiceCityAware;

    public function __construct(
        private readonly RootSlugs $rootSlugs,
        private readonly ServicePageRepository $pages,
        private readonly ServiceItemRepository $items,
        private readonly HtmlSitemap $htmlSitemap,
        private readonly Environment $twig,
    ) {
    }

    #[Route(
        path: ['uk' => '/{path}/', 'ru' => '/ru/{path}/'],
        name: 'sv_page',
        requirements: ['path' => '(?!ru/)[a-z0-9-]+(?:/[a-z0-9-]+)?'],
        priority: 5,
        condition: "request.attributes.has('service_city') and service('root_slugs').matches('page', request, params)",
    )]
    public function show(Request $request, string $path): Response
    {
        $site = $this->serviceSite($request);
        $city = $this->serviceCity($request);
        $page = $this->rootSlugs->page($site, $path, $request->getLocale())
            ?? throw $this->createNotFoundException('Сторінку не знайдено.');
        // сторінка не для цього міста (філії): 404, як і будь-яка відсутня
        if (!$page->isAvailableIn($city)) {
            throw $this->createNotFoundException('Сторінки в цьому місті нема.');
        }

        $locale = $request->getLocale() === 'ru' ? 'ru' : 'uk';
        $hubItems = [];
        if ($page->templateKind() === 'hub' && !$page->getItems()->isEmpty()) {
            $chosen = [];
            foreach ($page->getItems() as $item) {
                $chosen[(int) $item->getId()] = true;
            }
            $hubItems = array_values(array_filter($this->items->findActiveBySite($site, $city), static fn ($i): bool => isset($chosen[(int) $i->getId()])));
        }
        $noindex = !$page->isIndexable();
        $pageForm = '';
        if (($formKey = $page->formKey()) !== null) {
            // форма сторінки form:<ключ> — з паку чи рушія; нема шаблону — сторінка без форми (текст лишається)
            $form = 'services/page/form/' . $formKey . '.html.twig';
            if ($this->twig->getLoader()->exists($form)) {
                $pageForm = $this->renderView($form, ['site' => $site, 'city' => $city, 'page' => $page, 'locale' => $locale]);
            }
        }
        $response = $this->render('services/page/show.html.twig', [
            'site' => $site,
            'city' => $city,
            'page' => $page,
            'pageForm' => $pageForm,
            'children' => $page->getParent() === null ? $this->pages->findLiveChildren($page, $city) : [],
            'hubItems' => $hubItems,
            'htmlSitemap' => $page->templateKind() === 'sitemap' ? $this->htmlSitemap->build($site, $city, $locale) : null,
            'pageNoindex' => $noindex,
            'altUrls' => $this->altUrls('sv_page', ['path' => $page->path('uk')], ['path' => $page->path('ru')]),
        ]);
        if ($noindex) {
            // заголовок — і для паку чи живої теми, що перекриває base.html.twig без pageNoindex
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
