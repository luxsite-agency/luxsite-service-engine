<?php

// SPDX-License-Identifier: Apache-2.0
// Copyright 2026 LuxSite (https://luxsite.agency). See LICENSE and NOTICE.

declare(strict_types=1);

namespace App\AdminApi;

use App\AdminApi\Auth\ApiTokenContext;
use App\Entity\ServiceCategory;
use App\Entity\ServiceCategoryCity;
use App\Entity\ServiceCity;
use App\Entity\ServiceDistrict;
use App\Entity\ServiceItem;
use App\Entity\ServiceItemCity;
use App\Entity\ServicePost;
use App\Entity\ServicePrice;
use App\Entity\ServiceSite;
use App\Repository\ServiceCityRepository;
use App\Repository\ServiceSiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Операції контенту для менеджерів — одне ядро для REST (/api/admin/v1) і MCP (/mcp). Лише те, що менеджер і так
 * править в адмінці: тексти, SEO, FAQ, активність послуг/категорій/районів/постів, тексти по містах, прайс. Жодних
 * заявок, користувачів, сайтів, міст, медіа, slug. Дозволи перевіряються тут (ScopeVoter), зміни — одразу в БД з логом.
 */
final class ContentService
{
    public const string KINDS = 'items|categories|districts|posts|prices|item-texts|category-texts';

    /** Поля, які можна читати й правити, за видом запису (ключ JSON → властивість сутності). */
    public const array FIELDS = [
        'items' => ['title_uk' => 'titleUk', 'title_ru' => 'titleRu', 'lead_uk' => 'leadUk', 'lead_ru' => 'leadRu', 'desc_uk' => 'descUk', 'desc_ru' => 'descRu',
            'desc2_uk' => 'desc2Uk', 'desc2_ru' => 'desc2Ru', 'desc3_uk' => 'desc3Uk', 'desc3_ru' => 'desc3Ru', 'tab_title_uk' => 'tabTitleUk', 'tab_title_ru' => 'tabTitleRu',
            'meta_title_uk' => 'metaTitleUk', 'meta_title_ru' => 'metaTitleRu', 'meta_desc_uk' => 'metaDescUk', 'meta_desc_ru' => 'metaDescRu',
            'faq_uk' => 'faqUk', 'faq_ru' => 'faqRu', 'image_alt' => 'imageAlt', 'active' => 'active'],
        'categories' => ['title_uk' => 'titleUk', 'title_ru' => 'titleRu', 'desc_uk' => 'descUk', 'desc_ru' => 'descRu', 'desc2_uk' => 'desc2Uk', 'desc2_ru' => 'desc2Ru',
            'desc3_uk' => 'desc3Uk', 'desc3_ru' => 'desc3Ru', 'meta_title_uk' => 'metaTitleUk', 'meta_title_ru' => 'metaTitleRu', 'meta_desc_uk' => 'metaDescUk',
            'meta_desc_ru' => 'metaDescRu', 'image_alt' => 'imageAlt', 'active' => 'active', 'landing' => 'landing'],
        'districts' => ['name_uk' => 'nameUk', 'name_ru' => 'nameRu', 'title_uk' => 'titleUk', 'title_ru' => 'titleRu', 'lead_uk' => 'leadUk', 'lead_ru' => 'leadRu',
            'desc_uk' => 'descUk', 'desc_ru' => 'descRu', 'meta_title_uk' => 'metaTitleUk', 'meta_title_ru' => 'metaTitleRu', 'meta_desc_uk' => 'metaDescUk',
            'meta_desc_ru' => 'metaDescRu', 'sub_areas_uk' => 'subAreasUk', 'sub_areas_ru' => 'subAreasRu', 'faq_uk' => 'faqUk', 'faq_ru' => 'faqRu', 'active' => 'active'],
        'posts' => ['title_uk' => 'titleUk', 'title_ru' => 'titleRu', 'lead_uk' => 'leadUk', 'lead_ru' => 'leadRu', 'desc_uk' => 'descUk', 'desc_ru' => 'descRu',
            'meta_title_uk' => 'metaTitleUk', 'meta_title_ru' => 'metaTitleRu', 'meta_desc_uk' => 'metaDescUk', 'meta_desc_ru' => 'metaDescRu',
            'image_alt' => 'imageAlt', 'image_alt_ru' => 'imageAltRu', 'active' => 'active',
            // англійська (22.10.2026): без фолбеку на uk; слаг — як і uk/ru, лише в адмінці
            'title_en' => 'titleEn', 'lead_en' => 'leadEn', 'desc_en' => 'descEn', 'meta_title_en' => 'metaTitleEn',
            'meta_desc_en' => 'metaDescEn', 'image_alt_en' => 'imageAltEn'],
        'prices' => ['name_uk' => 'nameUk', 'name_ru' => 'nameRu', 'desc_uk' => 'descUk', 'desc_ru' => 'descRu', 'price' => 'price', 'price_prefix' => 'pricePrefix',
            'price_text_uk' => 'priceTextUk', 'price_text_ru' => 'priceTextRu', 'unit_uk' => 'unitUk', 'unit_ru' => 'unitRu', 'position' => 'position', 'active' => 'active', 'in_calculator' => 'inCalculator'],
        'item-texts' => ['title_uk' => 'titleUk', 'title_ru' => 'titleRu', 'lead_uk' => 'leadUk', 'lead_ru' => 'leadRu', 'desc_uk' => 'descUk', 'desc_ru' => 'descRu',
            'desc2_uk' => 'desc2Uk', 'desc2_ru' => 'desc2Ru', 'desc3_uk' => 'desc3Uk', 'desc3_ru' => 'desc3Ru', 'tab_title_uk' => 'tabTitleUk', 'tab_title_ru' => 'tabTitleRu',
            'meta_title_uk' => 'metaTitleUk', 'meta_title_ru' => 'metaTitleRu', 'meta_desc_uk' => 'metaDescUk', 'meta_desc_ru' => 'metaDescRu', 'faq_uk' => 'faqUk', 'faq_ru' => 'faqRu'],
        'category-texts' => ['title_uk' => 'titleUk', 'title_ru' => 'titleRu', 'desc_uk' => 'descUk', 'desc_ru' => 'descRu', 'desc2_uk' => 'desc2Uk', 'desc2_ru' => 'desc2Ru',
            'desc3_uk' => 'desc3Uk', 'desc3_ru' => 'desc3Ru', 'meta_title_uk' => 'metaTitleUk', 'meta_title_ru' => 'metaTitleRu', 'meta_desc_uk' => 'metaDescUk', 'meta_desc_ru' => 'metaDescRu'],
    ];
    private const array ENTITY = [
        'items' => ServiceItem::class, 'categories' => ServiceCategory::class, 'districts' => ServiceDistrict::class, 'posts' => ServicePost::class,
        'prices' => ServicePrice::class, 'item-texts' => ServiceItemCity::class, 'category-texts' => ServiceCategoryCity::class,
    ];
    private const array WRITE_SCOPE = ['prices' => 'scope:prices:write'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServiceSiteRepository $sites,
        private readonly ServiceCityRepository $cities,
        private readonly ApiTokenContext $context,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array<string, mixed> користувач, токен, сайти з містами */
    public function me(?\App\Entity\User $user): array
    {
        $token = $this->context->current();
        $sites = [];
        foreach ($this->sites->findAllActive() as $site) {
            if ($token !== null && !$token->allowsSite($site)) {
                continue;
            }
            $cities = [];
            foreach ($this->cities->findAllActiveBySite($site) as $city) {
                $cities[] = ['id' => $city->getId(), 'slug' => $city->getSlug(), 'host' => $city->getHost(), 'apex' => $city->isApex(), 'noindex' => $city->isNoindex()];
            }
            $sites[] = ['id' => $site->getId(), 'slug' => $site->getSlug(), 'name' => $site->getName(), 'apex' => $site->getApexDomain(), 'cities' => $cities, 'studio_api_url' => 'https://' . $site->getApexDomain() . '/api/studio'];
        }

        return [
            'user' => $user === null ? null : ['id' => $user->getId(), 'email' => $user->getEmail(), 'name' => $user->getDisplayName(), 'roles' => $user->getRoles()],
            'token' => $token === null ? null : ['id' => $token->getId(), 'name' => $token->getName(), 'prefix' => $token->getPrefix(), 'scopes' => $token->getScopeValues(), 'expires_at' => $token->getExpiresAt()->format(\DATE_ATOM)],
            'sites' => $sites,
        ];
    }

    /**
     * @param array<string, mixed> $filters q, city (slug), category (id), item (id)
     *
     * @return array<string, mixed>
     */
    public function list(string $siteSlug, string $kind, array $filters = []): array
    {
        $this->requireScope('scope:content:read');
        $site = $this->site($siteSlug);
        $kind = $this->kind($kind);
        $rows = [];
        foreach ($this->query($site, $kind, $filters) as $e) {
            $rows[] = $this->summary($kind, $e);
        }

        return ['site' => $site->getSlug(), 'kind' => $kind, 'count' => \count($rows), 'items' => $rows];
    }

    /** @return array<string, mixed> */
    public function get(string $siteSlug, string $kind, int $id): array
    {
        $this->requireScope('scope:content:read');
        $kind = $this->kind($kind);

        return $this->full($kind, $this->find($this->site($siteSlug), $kind, $id));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> запис після змін + changed
     */
    public function patch(string $siteSlug, string $kind, int $id, array $data): array
    {
        $this->requireScope('scope:content:write');
        $kind = $this->kind($kind);
        if (isset(self::WRITE_SCOPE[$kind])) {
            $this->requireScope(self::WRITE_SCOPE[$kind]);
        }
        $e = $this->find($this->site($siteSlug), $kind, $id);
        $changed = $this->apply($kind, $e, $data);
        $this->em->flush();
        $this->audit('update', $kind, $e, $changed);

        return $this->full($kind, $e) + ['changed' => array_keys($changed)];
    }

    /**
     * Тексти послуги/категорії для міста: upsert за (item|category, city).
     *
     * @param array<string, mixed> $data item_id|category_id, city_id, …поля
     *
     * @return array{0: array<string, mixed>, 1: bool} запис, created
     */
    public function upsertTexts(string $siteSlug, string $kind, array $data): array
    {
        $this->requireScope('scope:content:write');
        if (!\in_array($kind, ['item-texts', 'category-texts'], true)) {
            throw new ApiProblem('upsert лише для item-texts і category-texts', 400);
        }
        $site = $this->site($siteSlug);
        $city = $this->em->getRepository(ServiceCity::class)->findOneBy(['site' => $site, 'id' => (int) ($data['city_id'] ?? 0)]);
        if ($city === null) {
            throw new ApiProblem('city_id: міста нема в цьому сайті', 422);
        }
        if ($kind === 'item-texts') {
            $parent = $this->em->getRepository(ServiceItem::class)->findOneBy(['site' => $site, 'id' => (int) ($data['item_id'] ?? 0)])
                ?? throw new ApiProblem('item_id: послуги нема в цьому сайті', 422);
            $e = $this->em->getRepository(ServiceItemCity::class)->findOneBy(['item' => $parent, 'city' => $city]) ?? (new ServiceItemCity())->setItem($parent)->setCity($city);
        } else {
            $parent = $this->em->getRepository(ServiceCategory::class)->findOneBy(['site' => $site, 'id' => (int) ($data['category_id'] ?? 0)])
                ?? throw new ApiProblem('category_id: категорії нема в цьому сайті', 422);
            $e = $this->em->getRepository(ServiceCategoryCity::class)->findOneBy(['category' => $parent, 'city' => $city]) ?? (new ServiceCategoryCity())->setCategory($parent)->setCity($city);
        }
        unset($data['item_id'], $data['category_id'], $data['city_id']);
        $changed = $this->apply($kind, $e, $data);
        $created = $e->getId() === null;
        $this->em->persist($e);
        $this->em->flush();
        $this->audit($created ? 'create' : 'update', $kind, $e, $changed);

        return [$this->full($kind, $e) + ['changed' => array_keys($changed)], $created];
    }

    /** @return list<string> */
    public function fieldsOf(string $kind): array
    {
        return array_keys(self::FIELDS[$this->kind($kind)]);
    }

    private function requireScope(string $attribute): void
    {
        if (!$this->auth->isGranted($attribute)) {
            throw new ApiProblem(sprintf('Токену бракує дозволу %s або власнику — ролі для нього', substr($attribute, 6)), 403);
        }
    }

    private function kind(string $kind): string
    {
        if (!isset(self::FIELDS[$kind])) {
            throw new ApiProblem('Невідомий вид запису: ' . $kind . '. Дозволені: ' . str_replace('|', ', ', self::KINDS), 400);
        }

        return $kind;
    }

    private function site(string $slug): ServiceSite
    {
        $site = $this->sites->findActiveBySlug($slug) ?? throw new ApiProblem('Сайт не знайдено: ' . $slug, 404);
        // бренди токена й користувача (22.10.2026): чужий бренд — як неіснуючий для цього токена
        $token = $this->context->current();
        if ($token !== null && !$token->allowsSite($site)) {
            throw new ApiProblem('Токен не має доступу до сайту: ' . $slug, 403);
        }

        return $site;
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return list<object>
     */
    private function query(ServiceSite $site, string $kind, array $f): array
    {
        $repo = $this->em->getRepository(self::ENTITY[$kind]);
        $q = trim((string) ($f['q'] ?? ''));
        $citySlug = trim((string) ($f['city'] ?? ''));
        $city = $citySlug !== '' ? $this->em->getRepository(ServiceCity::class)->findOneBy(['site' => $site, 'slug' => $citySlug]) : null;
        $qb = $repo->createQueryBuilder('e');
        switch ($kind) {
            case 'items':
                $qb->where('e.site = :site')->setParameter('site', $site)->orderBy('e.position', 'ASC')->addOrderBy('e.id', 'ASC');
                if (!empty($f['category'])) {
                    $qb->andWhere('e.category = :cat')->setParameter('cat', (int) $f['category']);
                }
                if ($q !== '') {
                    $qb->andWhere('e.titleUk LIKE :q OR e.titleRu LIKE :q OR e.slugUk LIKE :q')->setParameter('q', '%' . $q . '%');
                }
                break;
            case 'categories':
            case 'posts':
                $qb->where('e.site = :site')->setParameter('site', $site)->orderBy('e.id', 'ASC');
                if ($q !== '') {
                    $qb->andWhere($kind === 'posts'
                        ? 'e.titleUk LIKE :q OR e.titleRu LIKE :q OR e.slugUk LIKE :q OR e.titleEn LIKE :q OR e.slugEn LIKE :q'
                        : 'e.titleUk LIKE :q OR e.titleRu LIKE :q OR e.slugUk LIKE :q')->setParameter('q', '%' . $q . '%');
                }
                break;
            case 'districts':
                $qb->join('e.city', 'c')->where('c.site = :site')->setParameter('site', $site)->orderBy('c.id', 'ASC')->addOrderBy('e.position', 'ASC');
                if ($city !== null) {
                    $qb->andWhere('e.city = :city')->setParameter('city', $city);
                }
                if ($q !== '') {
                    $qb->andWhere('e.nameUk LIKE :q OR e.nameRu LIKE :q OR e.slugUk LIKE :q')->setParameter('q', '%' . $q . '%');
                }
                break;
            case 'prices':
                $qb->join('e.city', 'c')->where('c.site = :site')->setParameter('site', $site)->orderBy('e.item', 'ASC')->addOrderBy('e.position', 'ASC');
                if ($city !== null) {
                    $qb->andWhere('e.city = :city')->setParameter('city', $city);
                }
                if (!empty($f['item'])) {
                    $qb->andWhere('e.item = :item')->setParameter('item', (int) $f['item']);
                }
                break;
            case 'item-texts':
            case 'category-texts':
                $qb->join('e.city', 'c')->where('c.site = :site')->setParameter('site', $site)->orderBy('e.id', 'ASC');
                if ($city !== null) {
                    $qb->andWhere('e.city = :city')->setParameter('city', $city);
                }
                break;
        }

        return $qb->getQuery()->getResult();
    }

    private function find(ServiceSite $site, string $kind, int $id): object
    {
        $e = $this->em->getRepository(self::ENTITY[$kind])->find($id);
        $ok = $e !== null && match ($kind) {
            'items', 'categories', 'posts' => $e->getSite()?->getId() === $site->getId(),
            default => $e->getCity()?->getSite()?->getId() === $site->getId(),
        };
        if (!$ok) {
            throw new ApiProblem(sprintf('%s #%d: нема в сайті %s', $kind, $id, $site->getSlug()), 404);
        }

        return $e;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, array{0: mixed, 1: mixed}> змінені поля: ключ → [було, стало]
     */
    private function apply(string $kind, object $e, array $data): array
    {
        if ($data === []) {
            throw new ApiProblem('Нема полів для зміни', 400);
        }
        $fields = self::FIELDS[$kind];
        $unknown = array_diff(array_keys($data), array_keys($fields));
        if ($unknown !== []) {
            throw new ApiProblem('Невідомі або заборонені поля: ' . implode(', ', $unknown) . '. Дозволені: ' . implode(', ', array_keys($fields)), 422);
        }
        $changed = [];
        foreach ($data as $key => $value) {
            $prop = $fields[$key];
            $before = $e->{$this->getter($e, $prop)}();
            if (\is_array($value) && str_starts_with($key, 'faq')) {
                $value = array_values(array_filter(array_map(static fn ($r) => \is_array($r) ? ['q' => (string) ($r['q'] ?? ''), 'a' => (string) ($r['a'] ?? '')] : null, $value)));
            }
            if (\is_bool($before) || \in_array($key, ['active', 'landing', 'in_calculator'], true)) {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (\is_int($before) || $key === 'position') {
                $value = (int) $value;
            } elseif ($value !== null && !\is_array($value)) {
                $value = (string) $value;
                if ($value === '' && $before === null) {
                    $value = null;
                }
            }
            if ($before === $value) {
                continue;
            }
            $e->{'set' . ucfirst($prop)}($value);
            $changed[$key] = [$before, $value];
        }
        if ($changed === []) {
            throw new ApiProblem('Нічого не змінено: значення збігаються з поточними', 200);
        }

        return $changed;
    }

    private function getter(object $e, string $prop): string
    {
        foreach (['get', 'is'] as $p) {
            if (method_exists($e, $p . ucfirst($prop))) {
                return $p . ucfirst($prop);
            }
        }
        throw new \LogicException('Нема геттера для ' . $prop);
    }

    /** @return array<string, mixed> */
    private function summary(string $kind, object $e): array
    {
        $row = ['id' => $e->getId()];
        switch ($kind) {
            case 'items':
                $row += ['slug' => $e->getSlugUk(), 'title_uk' => $e->getTitleUk(), 'title_ru' => $e->getTitleRu(), 'category_id' => $e->getCategory()?->getId(), 'active' => $e->isActive()];
                break;
            case 'categories':
                $row += ['slug' => $e->getSlugUk(), 'title_uk' => $e->getTitleUk(), 'title_ru' => $e->getTitleRu(), 'active' => $e->isActive(), 'landing' => $e->isLanding()];
                break;
            case 'districts':
                $row += ['slug' => $e->getSlugUk(), 'city' => $e->getCity()?->getSlug(), 'name_uk' => $e->getNameUk(), 'name_ru' => $e->getNameRu(), 'active' => $e->isActive(), 'primary' => $e->isPrimary()];
                break;
            case 'posts':
                $row += ['slug' => $e->getSlugUk(), 'title_uk' => $e->getTitleUk(), 'title_ru' => $e->getTitleRu(), 'active' => $e->isActive(), 'published_at' => $e->getPublishedAt()?->format(\DATE_ATOM)];
                if ($e->getSlugEn() !== null) {
                    $row += ['slug_en' => $e->getSlugEn(), 'title_en' => $e->getTitleEn()];
                }
                break;
            case 'prices':
                $row += ['item_id' => $e->getItem()?->getId(), 'city' => $e->getCity()?->getSlug(), 'name_uk' => $e->getNameUk(), 'price' => $e->getPrice(), 'price_prefix' => $e->getPricePrefix(), 'price_text_uk' => $e->getPriceTextUk(), 'active' => $e->isActive()];
                break;
            case 'item-texts':
                $row += ['item_id' => $e->getItem()?->getId(), 'city' => $e->getCity()?->getSlug(), 'title_uk' => $e->getTitleUk()];
                break;
            case 'category-texts':
                $row += ['category_id' => $e->getCategory()?->getId(), 'city' => $e->getCity()?->getSlug(), 'title_uk' => $e->getTitleUk()];
                break;
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function full(string $kind, object $e): array
    {
        $row = $this->summary($kind, $e);
        foreach (self::FIELDS[$kind] as $key => $prop) {
            $row[$key] = $e->{$this->getter($e, $prop)}();
        }

        return $row;
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changed */
    private function audit(string $action, string $kind, object $e, array $changed): void
    {
        $token = $this->context->current();
        $this->logger->info('admin_api.' . $action, [
            'kind' => $kind, 'id' => $e->getId(), 'fields' => array_keys($changed),
            'user_id' => $token?->getUser()->getId(), 'user' => $token?->getUser()->getEmail(),
            'token_id' => $token?->getId(), 'token_prefix' => $token?->getPrefix(),
        ]);
    }
}
