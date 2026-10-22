<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\Controller\Api;

use App\AdminApi\AdminApiTokens;
use App\AdminApi\ApiScope;
use App\Entity\ServicePost;
use App\Entity\ServiceSite;
use App\EventSubscriber\ServiceHostSubscriber;
use App\ServiceSite\SiteLocales;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Приймач статей від content-studio (зовнішній сервіс генерації статей).
 *
 *   GET /api/studio/ping                      — перевірка токена й тенанта
 *   PUT /api/studio/articles/{externalId}     — створити/оновити пост (upsert за site + group_id)
 *
 * Авторизація: Authorization: Bearer <токен> — або спільний STUDIO_API_TOKEN з .env.local (порожній = вимкнено), або
 * персональний токен менеджера svc_… з дозволом studio:write (адмінка → API-токени; 15.10.2026: менеджери самі
 * підключають сайт до content-studio своїм токеном, без доступу до .env на сервері).
 * Тенант (ServiceSite) береться з Host — як і решта сервісних роутів. Одна панельна стаття
 * однією мовою заповнює *Uk або *Ru поля одного ServicePost, спільного для перекладів (group_id).
 */
#[Route('/api/studio', name: 'api_studio_', priority: 20, condition: "request.attributes.has('service_site')")]
final class StudioArticleController extends AbstractController
{
    /** Заголовки блоків, які приймач дописує до тексту статті, за мовою */
    private const HEADINGS = [
        'faq' => ['uk' => 'Часті питання', 'ru' => 'Частые вопросы', 'en' => 'Frequently asked questions'],
        'related' => ['uk' => 'Читайте також', 'ru' => 'Читайте также', 'en' => 'Read also'],
        'sources' => ['uk' => 'Джерела', 'ru' => 'Источники', 'en' => 'Sources'],
    ];
    private const SLUG_MAX = 64;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
        private readonly \Symfony\Component\Routing\RouterInterface $router,
        #[Autowire(env: 'STUDIO_API_TOKEN')]
        private readonly string $apiToken,
        private readonly AdminApiTokens $tokens,
        private readonly \Symfony\Component\Security\Core\Role\RoleHierarchyInterface $roles,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        private readonly SiteLocales $locales,
    ) {
    }

    /** Заголовок дописаного блоку мовою статті (невідома мова — як uk, так було до en) */
    private static function heading(string $kind, string $lang): string
    {
        return self::HEADINGS[$kind][$lang] ?? self::HEADINGS[$kind]['uk'];
    }

    #[Route('/ping', name: 'ping', methods: ['GET'])]
    public function ping(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $site = $this->site($request);

        return $this->json(['ok' => true, 'site' => $site->getSlug(), 'apex' => $site->getApexDomain(), 'langs' => $this->locales->enabled($site)]);
    }

    #[Route('/articles/{externalId}', name: 'article_put', methods: ['PUT'], requirements: ['externalId' => '[A-Za-z0-9_-]{1,64}'])]
    public function put(Request $request, string $externalId): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $site = $this->site($request);

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Тіло має бути JSON-об’єктом.'], Response::HTTP_BAD_REQUEST);
        }

        // мова статті: одна з мов бренду; без lang — мова розгортання без префікса (uk на сайтах, створених до en)
        $langs = $this->locales->enabled($site);
        $lang = strtolower((string) ($data['lang'] ?? $this->locales->default()));
        $groupId = trim((string) ($data['group_id'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $body = (string) ($data['body_html'] ?? '');

        $errors = [];
        if (!\in_array($lang, $langs, true)) {
            $errors['lang'] = 'Підтримуються мови: ' . implode(', ', $langs);
        }
        if ($groupId === '' || !preg_match('/^[0-9a-f-]{36}$/', $groupId)) {
            $errors['group_id'] = 'Потрібен UUID групи перекладів.';
        }
        if ($title === '') {
            $errors['title'] = 'Порожній заголовок.';
        }
        if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            $errors['slug'] = 'Слаг: лише латиниця, цифри, дефіс.';
        } elseif (\strlen($slug) > self::SLUG_MAX) {
            $errors['slug'] = sprintf('Слаг довший за %d символів.', self::SLUG_MAX);
        }
        if (trim(strip_tags($body)) === '') {
            $errors['body_html'] = 'Порожній текст.';
        }
        if ($errors !== []) {
            return $this->json(['error' => 'Невалідні дані.', 'fields' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $repo = $this->em->getRepository(ServicePost::class);
        $post = $repo->findOneBy(['site' => $site, 'studioGroupId' => $groupId]);

        // Слаг має бути унікальним у межах сайту й мови: зайнятий іншим постом → 409
        $slugField = match ($lang) {
            'ru' => 'slugRu',
            'en' => 'slugEn',
            default => 'slugUk',
        };
        $clash = $repo->findOneBy(['site' => $site, $slugField => $slug]);
        if ($clash !== null && $clash !== $post) {
            return $this->json(['error' => sprintf('Слаг "%s" уже зайнятий постом #%d.', $slug, $clash->getId())], Response::HTTP_CONFLICT);
        }

        $created = false;
        if ($post === null) {
            $post = (new ServicePost())->setSite($site)->setStudioGroupId($groupId);
            $created = true;
        }

        // зовнішні посилання — nofollow noopener (панель теж це робить; тут страховка для інших відправників)
        $body = $this->nofollowExternal($body, $request->getHost());
        $credit = isset($data['cover_credit']) && \is_string($data['cover_credit']) && trim($data['cover_credit']) !== ''
            ? "\n<p class=\"post-photo-credit\" style=\"font-size:.85em;color:#6b7280\">" . htmlspecialchars(trim($data['cover_credit']), ENT_QUOTES) . "</p>\n" : '';
        $html = $body . $this->faqHtml($data['faq'] ?? [], $lang) . $this->relatedHtml($data['related'] ?? [], $lang) . $this->sourcesHtml($data['sources'] ?? [], $lang) . $credit;
        $lead = isset($data['lead']) ? trim((string) $data['lead']) : null;
        $metaTitle = isset($data['meta_title']) ? mb_substr(trim((string) $data['meta_title']), 0, 255) : null;
        $metaDesc = isset($data['meta_desc']) ? trim((string) $data['meta_desc']) : null;

        if ($lang === 'en') {
            // англійська — без фолбеку й без дублювання в uk (slug_uk може бути NULL, 22.10.2026)
            $post->setSlugEn($slug)->setTitleEn($title)->setLeadEn($lead)->setDescEn($html)
                ->setMetaTitleEn($metaTitle ?: null)->setMetaDescEn($metaDesc ?: null);
        } elseif ($lang === 'ru') {
            $post->setSlugRu($slug)->setTitleRu($title)->setLeadRu($lead)->setDescRu($html)
                ->setMetaTitleRu($metaTitle ?: null)->setMetaDescRu($metaDesc ?: null);
            if ($post->getSlugUk() === '') {
                // ru прийшла першою: uk-поля обов'язкові за схемою — тимчасово дублюємо
                $post->setSlugUk($slug)->setTitleUk($title);
            }
        } else {
            $post->setSlugUk($slug)->setTitleUk($title)->setLeadUk($lead)->setDescUk($html)
                ->setMetaTitleUk($metaTitle ?: null)->setMetaDescUk($metaDesc ?: null);
        }

        // Обкладинка: ручне фото (не studio-*) панель НЕ перетирає; alt — одне поле на пост, беремо з основної мови (uk)
        // або якщо ще порожній (грабля 2026-09-09: ru-переклад перезаписав alt на укр. сторінці, а панель — ручний webp)
        $manualCover = $post->getImageName() !== null && !str_starts_with((string) $post->getImageName(), 'studio-');
        if (!empty($data['cover_alt']) && !$manualCover) {
            $alt = mb_substr((string) $data['cover_alt'], 0, 255);
            // alt по мовах: uk → image_alt, ru → image_alt_ru (шаблони беруть post.imageAlt(locale) з фолбеком на uk)
            match ($lang) {
                'ru' => $post->setImageAltRu($alt),
                'en' => $post->setImageAltEn($alt),
                default => $post->setImageAlt($alt),
            };
        }
        if (!$manualCover && !empty($data['cover_url']) && \is_string($data['cover_url'])) {
            $name = $this->downloadCover((string) $data['cover_url'], $post->getImageName());
            if ($name !== null) {
                $post->setImageName($name);
            }
        }

        $publishedAt = null;
        if (!empty($data['published_at'])) {
            try {
                // панель шле ATOM зі своїм зсувом; зводимо до таймзони сайту, інакше свіжий пост «із майбутнього»
                // не проходить фільтр publishedAt <= now (грабля 09-09/09-10)
                $publishedAt = (new \DateTimeImmutable((string) $data['published_at']))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            } catch (\Exception) {
                return $this->json(['error' => 'published_at: невалідна дата.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }
        if ($post->getPublishedAt() === null) {
            $post->setPublishedAt($publishedAt ?? new \DateTimeImmutable());
        } elseif ($publishedAt !== null && $created) {
            $post->setPublishedAt($publishedAt);
        }
        $post->setActive(true)->setStudioSyncedAt(new \DateTimeImmutable());

        $this->em->persist($post);
        $this->em->flush();

        $url = $this->generateUrl('sv_blog_post', ['slug' => $post->slug($lang), '_locale' => $lang], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->json([
            'id' => (string) $post->getId(),
            'url' => $url,
            'external_id' => $externalId,
            'created' => $created,
        ], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    private function authorize(Request $request): ?JsonResponse
    {
        $header = (string) $request->headers->get('Authorization', '');
        $token = str_starts_with($header, 'Bearer ') ? trim(substr($header, 7)) : '';
        if ($token === '') {
            return $this->json(['error' => 'Потрібен токен: Authorization: Bearer …'], Response::HTTP_UNAUTHORIZED);
        }
        // персональний токен менеджера: чинний, власник активний, дозвіл studio:write і роль, що його дає
        if (AdminApiTokens::looksLikeToken($token)) {
            $personal = $this->tokens->findByPlain($token);
            if ($personal === null || !$personal->isUsable() || !$personal->getUser()->isActive()) {
                return $this->json(['error' => 'Недійсний токен.'], Response::HTTP_UNAUTHORIZED);
            }
            $scope = ApiScope::StudioWrite;
            if (!$personal->hasScope($scope) || !\in_array($scope->requiredRole(), $this->roles->getReachableRoleNames($personal->getUser()->getRoles()), true)) {
                return $this->json(['error' => 'Токену бракує дозволу studio:write.'], Response::HTTP_FORBIDDEN);
            }
            $this->tokens->touch($personal, $request->getClientIp());

            return null;
        }
        if ($this->apiToken === '' || !hash_equals($this->apiToken, $token)) {
            return $this->json(['error' => 'Невірний токен.'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }

    private function site(Request $request): ServiceSite
    {
        $site = $request->attributes->get(ServiceHostSubscriber::ATTR_SITE);
        if (!$site instanceof ServiceSite) {
            throw $this->createNotFoundException('Сервісний сайт не визначено за хостом.');
        }

        return $site;
    }

    /**
     * Обкладинка з панелі → public/media/service_post/studio-<sha1>.jpg (той самий каталог, що й Vich-мапінг
     * service_post_media). Повторна публікація з тим самим URL не перекачує файл.
     */
    private function downloadCover(string $url, ?string $current): ?string
    {
        if (!preg_match('~^https://~', $url)) {
            return null;
        }
        $name = 'studio-' . substr(sha1($url), 0, 16) . '.jpg';
        if ($current === $name) {
            return $name;
        }
        $dir = $this->projectDir . '/public/media/service_post';
        @mkdir($dir, 0775, true);
        try {
            $bin = $this->http->request('GET', $url, ['timeout' => 30])->getContent();
        } catch (\Throwable) {
            return null;
        }
        if (\strlen($bin) < 1000 || !@imagecreatefromstring($bin)) {
            return null;
        }
        file_put_contents($dir . '/' . $name, $bin);

        return $name;
    }

    /** rel="nofollow noopener" target="_blank" для всіх посилань на чужі хости */
    private function nofollowExternal(string $html, string $ownHost): string
    {
        $own = strtolower(preg_replace('/^www\./', '', $ownHost) ?? $ownHost);

        return preg_replace_callback('/<a\b([^>]*)>/i', static function (array $m) use ($own): string {
            $attrs = $m[1];
            if (!preg_match('/\bhref\s*=\s*"([^"]*)"/i', $attrs, $h) || !preg_match('~^https?://([^/:?#]+)~i', trim($h[1]), $hm)) {
                return $m[0];
            }
            $host = strtolower(preg_replace('/^www\./', '', $hm[1]) ?? $hm[1]);
            if ($host === $own || str_ends_with($host, '.' . $own)) {
                return $m[0];
            }
            if (preg_match('/\brel\s*=\s*"([^"]*)"/i', $attrs, $r)) {
                $vals = array_filter(array_map('trim', explode(' ', strtolower($r[1]))));
                foreach (['nofollow', 'noopener'] as $v) {
                    if (!\in_array($v, $vals, true)) {
                        $vals[] = $v;
                    }
                }
                $attrs = preg_replace('/\brel\s*=\s*"[^"]*"/i', 'rel="' . implode(' ', $vals) . '"', $attrs, 1) ?? $attrs;
            } else {
                $attrs .= ' rel="nofollow noopener"';
            }
            if (!preg_match('/\btarget\s*=/i', $attrs)) {
                $attrs .= ' target="_blank"';
            }

            return '<a' . $attrs . '>';
        }, $html) ?? $html;
    }

    /** @param mixed $faq */
    private function faqHtml(mixed $faq, string $lang): string
    {
        if (!\is_array($faq) || $faq === []) {
            return '';
        }
        $items = '';
        foreach ($faq as $row) {
            $q = trim((string) ($row['q'] ?? ''));
            $a = trim((string) ($row['a'] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            $items .= sprintf("<h3>%s</h3>\n<p>%s</p>\n", htmlspecialchars($q, ENT_QUOTES), htmlspecialchars($a, ENT_QUOTES));
        }

        return $items === '' ? '' : sprintf("\n<section class=\"post-faq\">\n<h2>%s</h2>\n%s</section>\n", self::heading('faq', $lang), $items);
    }

    /** «Читайте також» — внутрішні посилання на інші статті цього сайту (без nofollow) */
    private function relatedHtml(mixed $related, string $lang): string
    {
        if (!\is_array($related) || $related === []) {
            return '';
        }
        $items = '';
        foreach ($related as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            if ($url === '' || $title === '') {
                continue;
            }
            $path = str_starts_with($url, '/') ? $url : (string) (parse_url($url, PHP_URL_PATH) ?: $url);
            $items .= sprintf("<li><a href=\"%s\">%s</a></li>\n", htmlspecialchars($path, ENT_QUOTES), htmlspecialchars($title, ENT_QUOTES));
        }

        return $items === '' ? '' : sprintf("\n<section class=\"post-related\">\n<h2>%s</h2>\n<ul>\n%s</ul>\n</section>\n", self::heading('related', $lang), $items);
    }

    /**
     * GET /api/studio/pages — структура сайту для перелінковки: послуги, категорії, райони, статті обома мовами
     * з анкорами (назва, табова назва). Панель зливає це із sitemap.
     */
    #[Route('/pages', name: 'pages', methods: ['GET'])]
    public function pages(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }
        $site = $this->site($request);
        $langs = $this->locales->enabled($site);
        $pages = [];
        $push = function (string $type, string $lang, ?string $path, ?string $title, ?string $desc, array $anchors, int $priority) use (&$pages): void {
            if ($path === null || $title === null || $title === '') {
                return;
            }
            $pages[] = ['url' => $path, 'lang' => $lang, 'type' => $type, 'title' => $title, 'h1' => $title, 'description' => $desc,
                'anchors' => array_values(array_unique(array_filter(array_map(static fn ($a) => mb_strtolower(trim((string) $a)), $anchors)))), 'priority' => $priority];
        };
        // URL через роутер: у форках різні шаблони (/services/{slug}/ або /services/{category}/{slug}/)
        $routes = $this->router->getRouteCollection();
        $url = function (string $route, array $params, string $lang) use ($routes): ?string {
            $r = $routes->get($route) ?? $routes->get($route . '.' . $lang);
            if ($r === null) {
                return null;
            }
            $vars = array_flip($r->compile()->getVariables());
            try {
                return $this->generateUrl($route, array_intersect_key($params, $vars) + ['_locale' => $lang]);
            } catch (\Throwable) {
                return null;
            }
        };
        $em = $this->em;
        foreach ($em->getRepository(\App\Entity\ServiceItem::class)->findBy(['site' => $site, 'active' => true]) as $it) {
            foreach ($langs as $l) {
                $title = (string) $it->loc('title', $l);
                $cat = method_exists($it, 'getCategory') ? $it->getCategory() : null;
                $push('service', $l, $url('sv_service', ['slug' => $it->slug($l), 'category' => $cat?->slug($l) ?? ''], $l), $title, $it->loc('lead', $l), [$title, $it->loc('tabTitle', $l)], 2);
            }
        }
        foreach ($em->getRepository(\App\Entity\ServiceCategory::class)->findBy(['site' => $site]) as $c) {
            foreach ($langs as $l) {
                $title = (string) $c->loc('title', $l);
                $push('category', $l, $url('sv_category', ['category' => $c->slug($l), 'slug' => $c->slug($l)], $l), $title, null, [$title], 3);
            }
        }
        foreach ($em->getRepository(\App\Entity\ServicePost::class)->findBy(['site' => $site, 'active' => true]) as $p) {
            foreach ($langs as $l) {
                if (!$p->existsIn($l)) {
                    continue;
                }
                $push('post', $l, $url('sv_blog_post', ['slug' => $p->slug($l)], $l), (string) $p->loc('title', $l), $p->loc('lead', $l), [], 6);
            }
        }
        foreach ($em->getRepository(\App\Entity\ServiceDistrict::class)->findBy(['active' => true]) as $d) {
            // лише райони апекс-міста цього сайту: райони міст-піддоменів живуть на інших хостах
            $city = $d->getCity();
            if ($city === null || $city->getSite() !== $site || !$city->isApex()) {
                continue;
            }
            foreach ($langs as $l) {
                $push('district', $l, $url('sv_district', ['slug' => $d->slug($l)], $l), (string) $d->loc('name', $l), null, [], 4);
            }
        }

        return $this->json(['site' => $site->getSlug(), 'count' => \count($pages), 'pages' => $pages]);
    }

    /** @param mixed $sources */
    private function sourcesHtml(mixed $sources, string $lang): string
    {
        if (!\is_array($sources) || $sources === []) {
            return '';
        }
        $items = '';
        foreach ($sources as $row) {
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? '')) ?: parse_url($url, PHP_URL_HOST);
            $items .= sprintf("<li><a href=\"%s\" rel=\"nofollow noopener\" target=\"_blank\">%s</a></li>\n", htmlspecialchars($url, ENT_QUOTES), htmlspecialchars((string) $title, ENT_QUOTES));
        }

        return $items === '' ? '' : sprintf("\n<section class=\"post-sources\">\n<h2>%s</h2>\n<ul>\n%s</ul>\n</section>\n", self::heading('sources', $lang), $items);
    }
}
